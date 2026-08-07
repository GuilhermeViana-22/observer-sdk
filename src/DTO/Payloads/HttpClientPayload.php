<?php

declare(strict_types=1);

namespace Observer\DTO\Payloads;

use Observer\Contracts\Payload;

final readonly class HttpClientPayload implements Payload
{
    /**
     * @param array<string, mixed> $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public ?int $statusCode = null,
        public ?float $durationMs = null,
        public ?string $host = null,
        public ?int $responseSize = null,
        public bool $failed = false,
        public ?string $error = null,
        public array $headers = [],
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'method' => $this->method,
            'url' => $this->url,
            'host' => $this->host,
            'status_code' => $this->statusCode,
            'duration_ms' => $this->durationMs !== null ? round($this->durationMs, 3) : null,
            'response_size' => $this->responseSize,
            'failed' => $this->failed,
            'error' => $this->error,
            'headers' => $this->headers ?: null,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
