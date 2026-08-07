<?php

declare(strict_types=1);

namespace Observer\Transport;

use Observer\Contracts\Transport;
use Observer\DTO\Event;
use Observer\Enums\EventType;

/**
 * Acumula eventos em memória — o dublê oficial dos testes.
 *
 * Combinado com Observer::fake(), permite asserts sobre o que o SDK produziu
 * sem tocar disco nem rede.
 */
final class MemoryTransport implements Transport
{
    /** @var list<Event> */
    private array $events = [];

    private int $flushCount = 0;

    public function __construct(private readonly int $limit = 1000) {}

    public function send(Event $event): void
    {
        $this->events[] = $event;

        // Teto de segurança: um teste mal escrito não deve estourar a memória.
        if (count($this->events) > $this->limit) {
            array_shift($this->events);
        }
    }

    public function sendBatch(array $events): void
    {
        foreach ($events as $event) {
            $this->send($event);
        }
    }

    public function flush(): bool
    {
        $this->flushCount++;

        return true;
    }

    public function close(): void {}

    /**
     * @return list<Event>
     */
    public function events(?EventType $type = null): array
    {
        if ($type === null) {
            return $this->events;
        }

        return array_values(array_filter(
            $this->events,
            static fn (Event $e): bool => $e->type === $type,
        ));
    }

    public function first(?EventType $type = null): ?Event
    {
        return $this->events($type)[0] ?? null;
    }

    public function last(?EventType $type = null): ?Event
    {
        $events = $this->events($type);

        return $events === [] ? null : $events[array_key_last($events)];
    }

    public function count(?EventType $type = null): int
    {
        return count($this->events($type));
    }

    public function flushCount(): int
    {
        return $this->flushCount;
    }

    public function clear(): void
    {
        $this->events = [];
        $this->flushCount = 0;
    }
}
