<?php

declare(strict_types=1);

namespace Observer\DTO\Payloads;

use Observer\Contracts\Payload;

final readonly class QueuePayload implements Payload
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    /**
     * @param array<string, mixed>|null $exception
     */
    public function __construct(
        public string $job,
        public string $status,
        public ?string $queue = null,
        public ?string $connection = null,
        public ?string $jobId = null,
        public ?int $attempts = null,
        public ?float $durationMs = null,
        public ?array $exception = null,
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
