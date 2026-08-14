<?php

declare(strict_types=1);

namespace Observer\DTO\Payloads;

use Observer\Contracts\Payload;

final class LogPayload implements Payload
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $message,
        public readonly string $level,
        public readonly ?string $channel = null,
        public readonly array $context = [],
        public readonly array $extra = [],
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
