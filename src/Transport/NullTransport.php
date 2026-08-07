<?php

declare(strict_types=1);

namespace Observer\Transport;

use Observer\Contracts\Transport;
use Observer\DTO\Event;

/**
 * Descarta tudo, em tempo constante.
 *
 * É o transporte usado quando o SDK está desligado: o código da aplicação
 * continua chamando Observer::capture() sem nenhum custo real.
 */
final class NullTransport implements Transport
{
    private int $discarded = 0;

    public function send(Event $event): void
    {
        $this->discarded++;
    }

    public function sendBatch(array $events): void
    {
        $this->discarded += count($events);
    }

    public function flush(): bool
    {
        return true;
    }

    public function close(): void {}

    public function discardedCount(): int
    {
        return $this->discarded;
    }
}
