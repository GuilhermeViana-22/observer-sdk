<?php

declare(strict_types=1);

namespace Observer\DTO\Payloads;

use Observer\Contracts\Payload;

final readonly class RequestPayload implements Payload
{
    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     */
    public function __construct(
        public string $method,
        public string $url,
        public int $statusCode,
        public float $durationMs,
        public ?string $route = null,
        public ?string $action = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public array $headers = [],
        public array $query = [],
        public ?array $body = null,
        public ?int $memoryPeakKb = null,
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
