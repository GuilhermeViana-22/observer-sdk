<?php

declare(strict_types=1);

namespace Observer\Contracts;

use Observer\DTO\Event;

/**
 * Abstração de saída do SDK.
 *
 * O núcleo não sabe — e não deve saber — para onde os eventos vão.
 * A interface é batch-first para que o futuro HttpTransport possa agrupar
 * requisições sem que nada acima dele mude.
 */
interface Transport
{
    public function send(Event $event): void;

    /**
     * @param list<Event> $events
     */
    public function sendBatch(array $events): void;

    /**
     * Garante a entrega de tudo o que está pendente no transporte.
     */
    public function flush(): bool;

    /**
     * Libera recursos (handles de arquivo, conexões).
     */
    public function close(): void;
}
