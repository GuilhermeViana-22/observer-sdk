<?php

declare(strict_types=1);

namespace Observer\DTO\Payloads;

use Observer\Contracts\Payload;

final readonly class LogPayload implements Payload
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $message,
        public string $level,
        public ?string $channel = null,
        public array $context = [],
        public array $extra = [],
    ) {}

    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'level' => $this->level,
            'channel' => $this->channel,
            'context' => $this->context,
            'extra' => $this->extra,
        ];
    }
}
