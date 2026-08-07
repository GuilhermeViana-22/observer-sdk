<?php

declare(strict_types=1);

namespace Observer\DTO\Payloads;

use Observer\Contracts\Payload;

final readonly class QueryPayload implements Payload
{
    /**
     * @param list<mixed> $bindings
     * @param array<string, mixed>|null $origin Arquivo/linha da aplicação que originou a query
     */
    public function __construct(
        public string $sql,
        public float $durationMs,
        public string $connection,
        public ?string $driver = null,
        public array $bindings = [],
        public bool $slow = false,
        public ?array $origin = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'sql' => $this->sql,
            'duration_ms' => round($this->durationMs, 3),
            'connection' => $this->connection,
            'driver' => $this->driver,
            'bindings' => $this->bindings,
            'slow' => $this->slow,
            'origin' => $this->origin,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
