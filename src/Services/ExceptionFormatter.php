<?php

declare(strict_types=1);

namespace Observer\Services;

use Observer\DTO\Payloads\ExceptionPayload;
use Observer\DTO\StackFrame;
use Throwable;

/**
 * Converte um Throwable em payload estruturado.
 *
 * Responsabilidades: normalizar frames, marcar o que é código da aplicação
 * (in_app) versus vendor, anexar trechos de código ao redor da linha e
 * calcular um fingerprint estável para agrupamento no servidor.
 */
final class ExceptionFormatter
{
    /** @var array<string, list<string>> */
    private array $fileCache = [];

    public function __construct(
        private readonly ?string $basePath = null,
        private readonly int $frameLimit = 50,
        private readonly int $contextLines = 5,
    ) {}

    public function format(Throwable $exception, bool $handled = true, int $depth = 0): ExceptionPayload
    {
        $previous = $exception->getPrevious();

        return new ExceptionPayload(
            class: $exception::class,
            message: $exception->getMessage(),
            code: $exception->getCode(),
            file: $this->relative($exception->getFile()),
            line: $exception->getLine(),
            frames: $this->frames($exception),
            // Limita o encadeamento para não explodir o payload com cadeias longas.
            previous: $previous !== null && $depth < 3
                ? $this->format($previous, $handled, $depth + 1)
                : null,
            handled: $handled,
            fingerprint: $this->fingerprint($exception),
        );
    }

    /**
     * @return list<StackFrame>
     */
    public function frames(Throwable $exception): array
    {
        $frames = [];
        $trace = array_slice($exception->getTrace(), 0, $this->frameLimit);

        // O topo da pilha é o próprio ponto de lançamento, ausente em getTrace().
        array_unshift($trace, [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);

        foreach ($trace as $frame) {
            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '[internal]';
            $line = isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : 0;

            $frames[] = new StackFrame(
                file: $this->relative($file),
                line: $line,
                function: isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : null,
                class: isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : null,
                type: isset($frame['type']) && is_string($frame['type']) ? $frame['type'] : null,
                inApp: $this->isInApp($file),
                context: $this->codeContext($file, $line),
            );
        }

        return $frames;
    }

    /**
     * Identidade estável do erro: mesma classe + mesmo local = mesmo grupo.
     * A mensagem fica de fora porque costuma conter IDs variáveis.
     */
    public function fingerprint(Throwable $exception): string
    {
        return substr(hash('xxh128', implode('|', [
            $exception::class,
            $this->relative($exception->getFile()),
            (string) $exception->getLine(),
        ])), 0, 16);
    }

    public function isInApp(string $file): bool
    {
        if ($this->basePath === null || ! str_starts_with($file, $this->basePath)) {
            return false;
        }

        return ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR);
    }

    /**
     * @return array<int, string>
     */
    private function codeContext(string $file, int $line): array
    {
        if ($this->contextLines <= 0 || $line <= 0 || ! $this->isInApp($file)) {
            return [];
        }

        $lines = $this->readFile($file);

        if ($lines === []) {
            return [];
        }

        $start = max(0, $line - $this->contextLines - 1);
        $end = min(count($lines) - 1, $line + $this->contextLines - 1);

        $context = [];

        for ($i = $start; $i <= $end; $i++) {
            $context[$i + 1] = rtrim($lines[$i], "\r\n");
        }

        return $context;
    }

    /**
     * @return list<string>
     */
    private function readFile(string $file): array
    {
        if (isset($this->fileCache[$file])) {
            return $this->fileCache[$file];
        }

        if (! is_readable($file) || filesize($file) > 2 * 1024 * 1024) {
            return $this->fileCache[$file] = [];
        }

        $lines = @file($file);

        return $this->fileCache[$file] = $lines === false ? [] : $lines;
    }

    private function relative(string $file): string
    {
        if ($this->basePath === null) {
            return $file;
        }

        return str_starts_with($file, $this->basePath)
            ? ltrim(substr($file, strlen($this->basePath)), DIRECTORY_SEPARATOR)
            : $file;
    }
}
