<?php

declare(strict_types=1);

namespace Observer\Services;

use Observer\Support\Str;

/**
 * Mascara dados sensíveis e impõe os limites rígidos de tamanho.
 *
 * Percorre estruturas arbitrárias uma única vez, aplicando simultaneamente:
 * mascaramento por nome de chave, truncamento de strings, corte de arrays
 * longos e limite de profundidade.
 */
final class Redactor
{
    /** @var list<string> */
    private readonly array $keys;

    /** @var list<string> */
    private readonly array $headers;

    /**
     * @param list<string> $keys
     * @param list<string> $headers
     */
    public function __construct(
        array $keys = [],
        array $headers = [],
        private readonly string $mask = '[FILTERED]',
        private readonly bool $enabled = true,
        private readonly int $maxStringLength = 4096,
        private readonly int $maxArrayItems = 100,
        private readonly int $maxDepth = 8,
    ) {
        $this->keys = array_map(strtolower(...), $keys);
        $this->headers = array_map(strtolower(...), $headers);
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public function scrub(array $data): array
    {
        /** @var array<array-key, mixed> $result */
        $result = $this->walk($data, 0);

        return $result;
    }

    /**
     * Headers têm lista própria e são sempre normalizados para minúsculas.
     *
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    public function scrubHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $value) {
            $normalized = strtolower((string) $name);

            $result[$normalized] = $this->enabled && $this->isSensitiveHeader($normalized)
                ? $this->mask
                : $this->walk(is_array($value) && count($value) === 1 ? reset($value) : $value, 1);
        }

        return $result;
    }

    public function isSensitiveKey(string $key): bool
    {
        return Str::matchesAny(strtolower($key), $this->keys);
    }

    private function isSensitiveHeader(string $name): bool
    {
        return Str::matchesAny($name, $this->headers) || $this->isSensitiveKey($name);
    }

    private function walk(mixed $value, int $depth): mixed
    {
        if ($depth > $this->maxDepth) {
            return '[MAX_DEPTH]';
        }

        if (is_string($value)) {
            return Str::truncate($value, $this->maxStringLength);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_object($value)) {
            $value = $this->objectToArray($value);
        }

        if (! is_array($value)) {
            return '['.get_debug_type($value).']';
        }

        $result = [];
        $count = 0;

        foreach ($value as $key => $item) {
            if ($count >= $this->maxArrayItems) {
                $result['...'] = sprintf('[%d itens omitidos]', count($value) - $this->maxArrayItems);
                break;
            }

            $result[$key] = $this->enabled && is_string($key) && $this->isSensitiveKey($key)
                ? $this->mask
                : $this->walk($item, $depth + 1);

            $count++;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|string
     */
    private function objectToArray(object $value): array|string
    {
        if ($value instanceof \JsonSerializable) {
            $data = $value->jsonSerialize();

            return is_array($data) ? $data : ['value' => $data];
        }

        if (method_exists($value, 'toArray')) {
            /** @var array<string, mixed> $data */
            $data = $value->toArray();

            return $data;
        }

        if ($value instanceof \Stringable || method_exists($value, '__toString')) {
            return (string) $value;
        }

        return ['class' => $value::class] + get_object_vars($value);
    }
}
