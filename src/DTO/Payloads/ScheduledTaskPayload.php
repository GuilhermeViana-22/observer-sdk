<?php

declare(strict_types=1);

namespace Observer\DTO\Payloads;

use Observer\Contracts\Payload;

final class ScheduledTaskPayload implements Payload
{
    public const STATUS_STARTED = 'started';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public function __construct(
        public readonly string $task,
        public readonly string $status,
        public readonly ?string $expression = null,
        public readonly ?string $description = null,
        public readonly ?float $durationMs = null,
        public readonly ?int $exitCode = null,
        public readonly ?string $output = null,
        public readonly ?string $error = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'task' => $this->task,
            'status' => $this->status,
            'expression' => $this->expression,
            'description' => $this->description,
            'duration_ms' => $this->durationMs !== null ? round($this->durationMs, 3) : null,
            'exit_code' => $this->exitCode,
            'output' => $this->output,
            'error' => $this->error,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
