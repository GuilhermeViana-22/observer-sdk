<?php

declare(strict_types=1);

namespace Observer\DTO\Payloads;

use Observer\Contracts\Payload;

final readonly class CommandPayload implements Payload
{
    public const STATUS_STARTED = 'started';

    public const STATUS_FINISHED = 'finished';

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $command,
        public string $status,
        public ?int $exitCode = null,
        public ?float $durationMs = null,
        public array $arguments = [],
        public array $options = [],
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'command' => $this->command,
            'status' => $this->status,
            'exit_code' => $this->exitCode,
            'duration_ms' => $this->durationMs !== null ? round($this->durationMs, 3) : null,
            'arguments' => $this->arguments ?: null,
            'options' => $this->options ?: null,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
