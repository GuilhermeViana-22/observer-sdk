<?php

declare(strict_types=1);

namespace Observer\Buffer;

use Observer\Contracts\Transport;
use Observer\DTO\Event;
use Observer\Support\InternalLogger;

/**
 * Fila em memória com política de drenagem por tamanho.
 *
 * Trade-off assumido: trocamos durabilidade por desempenho. Um lote de 50
 * eventos custa 1 I/O em vez de 50; em compensação, um kill -9 perde o que
 * estiver acumulado — daí o flush no shutdown e no terminate da request.
 *
 * Com `enabled = false`, o buffer vira passthrough e cada evento vai direto
 * ao transporte (útil para depuração local).
 */
final class EventBuffer
{
    /** @var list<Event> */
    private array $events = [];

    private int $dropped = 0;

    public function __construct(
        private readonly Transport $transport,
        private readonly int $maxSize = 50,
        private readonly bool $enabled = true,
        private readonly ?InternalLogger $logger = null,
    ) {}

    public function add(Event $event): void
    {
        if (! $this->enabled || $this->maxSize <= 1) {
            $this->transport->send($event);

            return;
        }

        $this->events[] = $event;

        if (count($this->events) >= $this->maxSize) {
            $this->flush();
        }
    }

    /**
     * Drena o buffer para o transporte. Sempre esvazia a fila, mesmo em erro:
     * segurar eventos que já falharam só faria a memória crescer.
     */
    public function flush(): bool
    {
        if ($this->events === []) {
            return $this->transport->flush();
        }

        $batch = $this->events;
        $this->events = [];

        try {
            $this->transport->sendBatch($batch);

            return $this->transport->flush();
        } catch (\Throwable $e) {
            $this->dropped += count($batch);
            $this->logger?->report($e, 'buffer_flush');

            return false;
        }
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }

    public function droppedCount(): int
    {
        return $this->dropped;
    }

    /**
     * Descarta o conteúdo sem enviar. Usado ao reiniciar o escopo em testes.
     */
    public function clear(): void
    {
        $this->events = [];
    }

    public function transport(): Transport
    {
        return $this->transport;
    }
}
