<?php

declare(strict_types=1);

namespace Observer\Support;

use Observer\Enums\EventType;
use Observer\Enums\Severity;

/**
 * Acesso tipado à configuração do SDK.
 *
 * Existe para que nenhuma string mágica de config vaze para dentro do núcleo:
 * collectors, pipeline e transporte recebem este objeto, não o helper config().
 *
 * É um snapshot imutável, tirado quando o singleton é resolvido — ler o
 * repositório do Laravel a cada evento custaria caro em caminhos quentes como
 * o QueryCollector. Alterar a config em runtime exige recriar o singleton
 * (é o que Observer::fake() faz).
 */
final class Configuration
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(private readonly array $items = []) {}

    /**
     * @param array<string, mixed> $items
     */
    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key, $default), FILTER_VALIDATE_BOOL);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param list<mixed> $default
     * @return list<mixed>
     */
    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        return is_array($value) ? $value : $default;
    }

    public function isEnabled(): bool
    {
        if (! $this->bool('enabled', true)) {
            return false;
        }

        $allowed = $this->array('environments');

        return $allowed === [] || in_array($this->environment(), $allowed, true);
    }

    public function environment(): string
    {
        return $this->string('environment', 'production') ?? 'production';
    }

    public function collectorEnabled(string $collector): bool
    {
        return $this->bool("collectors.{$collector}.enabled", false);
    }

    /**
     * @return array<string, mixed>
     */
    public function collector(string $collector): array
    {
        $value = $this->get("collectors.{$collector}", []);

        return is_array($value) ? $value : [];
    }

    /**
     * Taxa de amostragem específica do tipo, com fallback para a global.
     */
    public function sampleRateFor(EventType $type): float
    {
        $specific = $this->get("sample_rates.{$type->value}");

        if (is_numeric($specific)) {
            return (float) $specific;
        }

        return $this->float('sample_rate', 1.0);
    }

    public function minimumLogLevel(): Severity
    {
        return Severity::fromPsrLevel($this->string('collectors.logs.level', 'debug') ?? 'debug');
    }

    public function sendDefaultPii(): bool
    {
        return $this->bool('scrubbing.send_default_pii', false);
    }

    public function debug(): bool
    {
        return $this->bool('debug', false);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }
}
