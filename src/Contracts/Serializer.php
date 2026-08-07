<?php

declare(strict_types=1);

namespace Observer\Contracts;

use Observer\DTO\Event;

/**
 * Converte eventos no formato de fio (wire format).
 *
 * Isolar a serialização aqui permite trocar JSON por MessagePack/Protobuf
 * sem tocar em transporte, buffer ou collectors.
 */
interface Serializer
{
    public function serialize(Event $event): string;

    /**
     * @param list<Event> $events
     */
    public function serializeBatch(array $events): string;

    /**
     * Content-Type correspondente, usado pelo HttpTransport.
     */
    public function contentType(): string;
}
