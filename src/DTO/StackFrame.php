<?php

declare(strict_types=1);

namespace Observer\DTO;

use Observer\Contracts\Payload;

final readonly class StackFrame implements Payload
{
    /**
     * @param array<int, string> $context Trechos de código ao redor da linha, indexados pelo nº da linha
     */
    public function __construct(
        public string $file,
        public int $line,
        public ?string $function = null,
        public ?string $class = null,
        public ?string $type = null,
        public bool $inApp = true,
        public array $context = [],
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
