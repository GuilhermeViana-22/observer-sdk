<?php

declare(strict_types=1);

namespace Observer\DTO\Payloads;

use Observer\Contracts\Payload;

final readonly class ScheduledTaskPayload implements Payload
{
    public const STATUS_STARTED = 'started';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public function __construct(
        public string $task,
        public string $status,
        public ?string $expression = null,
        public ?string $description = null,
        public ?float $durationMs = null,
        public ?int $exitCode = null,
        public ?string $output = null,
        public ?string $error = null,
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
