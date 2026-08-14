<?php

declare(strict_types=1);

namespace Observer\DTO\Payloads;

use Observer\Contracts\Payload;
use Observer\DTO\StackFrame;

final class ExceptionPayload implements Payload
{
    /**
     * @param list<StackFrame> $frames
     * @param self|null $previous Exception encadeada
     */
    public function __construct(
        public readonly string $class,
        public readonly string $message,
        public readonly int|string $code,
        public readonly string $file,
        public readonly int $line,
        public readonly array $frames = [],
        public readonly ?self $previous = null,
        public readonly bool $handled = true,
        public readonly ?string $fingerprint = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'class' => $this->class,
            'message' => $this->message,
            'code' => $this->code,
            'file' => $this->file,
            'line' => $this->line,
            'handled' => $this->handled,
            'fingerprint' => $this->fingerprint,
            'frames' => array_map(
                static fn (StackFrame $frame): array => $frame->toArray(),
                $this->frames,
            ),
            'previous' => $this->previous?->toArray(),
        ], static fn (mixed $v): bool => $v !== null);
    }
}
