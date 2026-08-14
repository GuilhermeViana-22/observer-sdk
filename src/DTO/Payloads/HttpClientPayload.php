<?php

declare(strict_types=1);

namespace Observer\DTO\Payloads;

use Observer\Contracts\Payload;

final class HttpClientPayload implements Payload
{
    /**
     * @param array<string, mixed> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly ?int $statusCode = null,
        public readonly ?float $durationMs = null,
        public readonly ?string $host = null,
        public readonly ?int $responseSize = null,
        public readonly bool $failed = false,
        public readonly ?string $error = null,
        public readonly array $headers = [],
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
