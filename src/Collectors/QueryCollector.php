<?php

declare(strict_types=1);

namespace Observer\Collectors;

use Illuminate\Database\Events\QueryExecuted;
use Observer\DTO\Payloads\QueryPayload;
use Observer\Enums\EventType;
use Observer\Enums\Severity;
use Observer\Support\Str;

/**
 * Captura toda query executada via QueryExecuted (o mesmo evento por trás
 * de DB::listen), incluindo tempo, conexão, driver e bindings.
 */
final class QueryCollector extends AbstractCollector
{
    /**
     * Frames do próprio framework/SDK são descartados ao procurar a origem
     * da query no código da aplicação.
     */
    private const ORIGIN_SKIP = [
        '/vendor/laravel/framework/',
        '/vendor/observer/sdk/',
        '/vendor/doctrine/',
    ];

    public function name(): string
    {
        return 'queries';
    }

    public function register(): void
    {
        $this->listen(QueryExecuted::class, function (QueryExecuted $query): void {
            $threshold = $this->floatOption('slow_threshold_ms', 200);
            $slow = $query->time >= $threshold;

            $payload = new QueryPayload(
                sql: $query->sql,
                durationMs: $query->time,
                connection: $query->connectionName,
                driver: $this->driver($query),
                bindings: $this->boolOption('capture_bindings', true)
                    ? $this->normalizeBindings($query->bindings)
                    : [],
                slow: $slow,
                origin: $this->boolOption('capture_origin', true) ? $this->origin() : null,
            );

            $this->record(
                EventType::Query,
                Str::truncate($query->sql, 200),
                $payload,
                $slow ? Severity::Warning : Severity::Debug,
            );
        });
    }

    private function driver(QueryExecuted $query): ?string
    {
        $driver = $query->connection->getConfig('driver');

        return is_string($driver) ? $driver : null;
    }

    /**
     * Bindings podem conter objetos (DateTime, enums, binários). Converte para
     * escalares seguros — o Redactor cuida do mascaramento depois.
     *
     * @param array<array-key, mixed> $bindings
     * @return list<mixed>
     */
    private function normalizeBindings(array $bindings): array
    {
        return array_values(array_map(static function (mixed $binding): mixed {
            if ($binding instanceof \DateTimeInterface) {
                return $binding->format('Y-m-d H:i:s');
            }

            if ($binding instanceof \BackedEnum) {
                return $binding->value;
            }

            if (is_object($binding)) {
                return method_exists($binding, '__toString') ? (string) $binding : $binding::class;
            }

            if (is_string($binding) && ! mb_check_encoding($binding, 'UTF-8')) {
                return '[binary '.strlen($binding).' bytes]';
            }

            return $binding;
        }, $bindings));
    }

    /**
     * Primeiro frame que pertence ao código da aplicação — responde
     * "que linha do meu código disparou essa query?".
     *
     * @return array<string, mixed>|null
     */
    private function origin(): ?array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;

            if (! is_string($file) || $this->isVendorFrame($file)) {
                continue;
            }

            return [
                'file' => $file,
                'line' => $frame['line'] ?? 0,
                'function' => $frame['function'],
            ];
        }

        return null;
    }

    private function isVendorFrame(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);

        foreach (self::ORIGIN_SKIP as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return str_contains($normalized, '/vendor/');
    }
}
