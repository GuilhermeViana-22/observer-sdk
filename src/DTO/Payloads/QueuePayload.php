<?php

declare(strict_types=1);

namespace Observer\DTO\Payloads;

use Observer\Contracts\Payload;

final class QueuePayload implements Payload
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    /**
     * @param array<string, mixed>|null $exception
     */
    public function __construct(
        public readonly string $job,
        public readonly string $status,
        public readonly ?string $queue = null,
        public readonly ?string $connection = null,
        public readonly ?string $jobId = null,
        public readonly ?int $attempts = null,
        public readonly ?float $durationMs = null,
        public readonly ?array $exception = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'job' => $this->job,
            'status' => $this->status,
            'queue' => $this->queue,
            'connection' => $this->connection,
            'job_id' => $this->jobId,
            'attempts' => $this->attempts,
            'duration_ms' => $this->durationMs !== null ? round($this->durationMs, 3) : null,
            'exception' => $this->exception,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
