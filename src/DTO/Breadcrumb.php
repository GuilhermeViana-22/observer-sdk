<?php

declare(strict_types=1);

namespace Observer\DTO;

use Observer\Contracts\Payload;
use Observer\Enums\Severity;
use Observer\Support\Clock;

/**
 * Migalha de trilha: o histórico recente que antecedeu um evento.
 */
final readonly class Breadcrumb implements Payload
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $category,
        public string $message,
        public Severity $level = Severity::Info,
        public array $data = [],
        public ?float $timestamp = null,
    ) {}

    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'message' => $this->message,
            'level' => $this->level->value,
            'data' => $this->data,
            'timestamp' => $this->timestamp ?? Clock::now(),
        ];
    }
}
