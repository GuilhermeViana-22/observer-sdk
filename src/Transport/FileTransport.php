<?php

declare(strict_types=1);

namespace Observer\Transport;

use Observer\Contracts\Transport;
use Observer\DTO\Event;
use Observer\Serializers\JsonSerializer;
use Observer\Support\InternalLogger;

/**
 * Grava eventos em disco no formato JSON Lines (ndjson).
 *
 * Um evento por linha: o arquivo é lido incrementalmente, sobrevive a
 * escritas concorrentes (LOCK_EX) e pode ser processado com `jq`/`tail -f`.
 * Faz rotação por tamanho, mantendo N arquivos históricos.
 */
final class FileTransport implements Transport
{
    public function __construct(
        private readonly string $path,
        private readonly JsonSerializer $serializer = new JsonSerializer,
        private readonly int $maxSize = 10 * 1024 * 1024,
        private readonly int $maxFiles = 5,
        private readonly int $permission = 0644,
        private readonly ?InternalLogger $logger = null,
    ) {}

    public function send(Event $event): void
    {
        $this->sendBatch([$event]);
    }

    public function sendBatch(array $events): void
    {
        if ($events === []) {
            return;
        }

        try {
            $this->ensureDirectory();
            $this->rotateIfNeeded();

            $written = @file_put_contents(
                $this->path,
                $this->serializer->serializeLines($events),
                FILE_APPEND | LOCK_EX,
            );

            if ($written !== false) {
                @chmod($this->path, $this->permission);
            }
        } catch (\Throwable $e) {
            // Disco cheio ou permissão negada não podem derrubar a aplicação.
            $this->logger?->report($e, 'file_transport');
        }
    }

    public function flush(): bool
    {
        // A escrita já é síncrona; não há estado pendente no transporte.
        return true;
    }

    public function close(): void {}

    public function path(): string
    {
        return $this->path;
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }
    }

    /**
     * Rotação estilo logrotate: observer.ndjson → observer.ndjson.1 → …
     */
    private function rotateIfNeeded(): void
    {
        if ($this->maxSize <= 0 || ! is_file($this->path)) {
            return;
        }

        clearstatcache(true, $this->path);

        if ((filesize($this->path) ?: 0) < $this->maxSize) {
            return;
        }

        if ($this->maxFiles <= 0) {
            @unlink($this->path);

            return;
        }

        @unlink("{$this->path}.{$this->maxFiles}");

        for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
            if (is_file("{$this->path}.{$i}")) {
                @rename("{$this->path}.{$i}", $this->path.'.'.($i + 1));
            }
        }

        @rename($this->path, "{$this->path}.1");
    }
}
