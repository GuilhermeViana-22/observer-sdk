<?php

declare(strict_types=1);

namespace Observer\DTO\Payloads;

use Observer\Contracts\Payload;

final class RequestPayload implements Payload
{
    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly int $statusCode,
        public readonly float $durationMs,
        public readonly ?string $route = null,
        public readonly ?string $action = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly array $headers = [],
        public readonly array $query = [],
        public readonly ?array $body = null,
        public readonly ?int $memoryPeakKb = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'method' => $this->method,
            'url' => $this->url,
            'status_code' => $this->statusCode,
            'duration_ms' => round($this->durationMs, 3),
            'route' => $this->route,
            'action' => $this->action,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'headers' => $this->headers,
            'query' => $this->query,
            'body' => $this->body,
            'memory_peak_kb' => $this->memoryPeakKb,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
