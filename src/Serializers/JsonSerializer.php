<?php

declare(strict_types=1);

namespace Observer\Serializers;

use Observer\Contracts\Serializer;
use Observer\DTO\Event;

/**
 * Serializador padrão.
 *
 * Usa JSON_PARTIAL_OUTPUT_ON_ERROR e INVALID_UTF8_SUBSTITUTE: é preferível
 * entregar um evento com um campo degradado a perder o evento inteiro por
 * causa de um byte inválido vindo do banco.
 */
final class JsonSerializer implements Serializer
{
    private const FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR;

    public function __construct(private readonly bool $pretty = false) {}

    public function serialize(Event $event): string
    {
        return $this->encode($event->toArray());
    }

    /**
     * @param list<Event> $events
     */
    public function serializeBatch(array $events): string
    {
        return $this->encode([
            'events' => array_map(static fn (Event $e): array => $e->toArray(), $events),
            'count' => count($events),
        ]);
    }

    /**
     * Uma linha JSON por evento — formato consumido pelo FileTransport.
     *
     * @param list<Event> $events
     */
    public function serializeLines(array $events): string
    {
        $lines = array_map(fn (Event $e): string => $this->encode($e->toArray(), false), $events);

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    public function contentType(): string
    {
        return 'application/json';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data, ?bool $pretty = null): string
    {
        $flags = self::FLAGS;

        if ($pretty ?? $this->pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($data, $flags);

        return $json === false
            ? '{"error":"observer_serialization_failed"}'
            : $json;
    }
}
