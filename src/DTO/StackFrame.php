<?php

declare(strict_types=1);

namespace Observer\DTO;

use Observer\Contracts\Payload;

final class StackFrame implements Payload
{
    /**
     * @param array<int, string> $context Trechos de código ao redor da linha, indexados pelo nº da linha
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly ?string $function = null,
        public readonly ?string $class = null,
        public readonly ?string $type = null,
        public readonly bool $inApp = true,
        public readonly array $context = [],
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'file' => $this->file,
            'line' => $this->line,
            'function' => $this->function,
            'class' => $this->class,
            'type' => $this->type,
            'in_app' => $this->inApp,
            'context' => $this->context ?: null,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
