<?php

declare(strict_types=1);

namespace Observer\DTO\Payloads;

use Observer\Contracts\Payload;
use Observer\DTO\StackFrame;

final readonly class ExceptionPayload implements Payload
{
    /**
     * @param list<StackFrame> $frames
     * @param self|null $previous Exception encadeada
     */
    public function __construct(
        public string $class,
        public string $message,
        public int|string $code,
        public string $file,
        public int $line,
        public array $frames = [],
        public ?self $previous = null,
        public bool $handled = true,
        public ?string $fingerprint = null,
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
