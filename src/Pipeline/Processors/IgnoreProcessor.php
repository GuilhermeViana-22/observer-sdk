<?php

declare(strict_types=1);

namespace Observer\Pipeline\Processors;

use Observer\Contracts\EventProcessor;
use Observer\DTO\Event;
use Observer\Enums\EventType;
use Observer\Support\Configuration;
use Observer\Support\Str;

/**
 * Aplica as listas de exclusão configuradas.
 *
 * Exceptions de fluxo (validação, 404, autenticação) são ruído: elas fazem
 * parte da operação normal e afogariam o dashboard se coletadas.
 */
final class IgnoreProcessor implements EventProcessor
{
    public function __construct(private readonly Configuration $config) {}

    public function process(Event $event): ?Event
    {
        return match ($event->type) {
            EventType::Exception => $this->filterException($event),
            EventType::Query => $this->filterQuery($event),
            EventType::Request => $this->filterRequest($event),
            EventType::Cache => $this->filterCache($event),
            EventType::HttpClient => $this->filterHttpClient($event),
            EventType::Queue => $this->filterQueue($event),
            EventType::Command => $this->filterCommand($event),
            default => $event,
        };
    }

    private function filterException(Event $event): ?Event
    {
        $class = $this->stringField($event, 'class');

        if ($class === null) {
            return $event;
        }

        /** @var list<string> $ignored */
        $ignored = $this->config->array('collectors.exceptions.ignore');

        foreach ($ignored as $pattern) {
            // Comparação por hierarquia: ignorar a base ignora as filhas.
            if ($class === $pattern || is_subclass_of($class, $pattern) || Str::matches($class, $pattern)) {
                return null;
            }
        }

        return $event;
    }

    private function filterQuery(Event $event): ?Event
    {
        $sql = $this->stringField($event, 'sql');

        if ($sql !== null && Str::matchesAny($sql, $this->patterns('collectors.queries.ignore'))) {
            return null;
        }

        // Modo "só as lentas": descarta tudo que estiver abaixo do limiar.
        if ($this->config->bool('collectors.queries.only_slow') && ($event->payload['slow'] ?? false) !== true) {
            return null;
        }

        return $event;
    }

    private function filterRequest(Event $event): ?Event
    {
        $url = $this->stringField($event, 'url');

        if ($url === null) {
            return $event;
        }

        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        return Str::matchesAny($path, $this->patterns('collectors.requests.ignore_paths')) ? null : $event;
    }

    private function filterCache(Event $event): ?Event
    {
        $key = $this->stringField($event, 'key');
        $operation = $this->stringField($event, 'operation');

        if ($key !== null && Str::matchesAny($key, $this->patterns('collectors.cache.ignore_keys'))) {
            return null;
        }

        /** @var list<string> $operations */
        $operations = $this->config->array('collectors.cache.operations', ['hit', 'miss', 'write', 'forget']);

        return $operation === null || in_array($operation, $operations, true) ? $event : null;
    }

    private function filterHttpClient(Event $event): ?Event
    {
        $host = $this->stringField($event, 'host');

        return $host !== null && Str::matchesAny($host, $this->patterns('collectors.http_client.ignore_hosts'))
            ? null
            : $event;
    }

    private function filterQueue(Event $event): ?Event
    {
        $job = $this->stringField($event, 'job');

        return $job !== null && Str::matchesAny($job, $this->patterns('collectors.queue.ignore_jobs'))
            ? null
            : $event;
    }

    private function filterCommand(Event $event): ?Event
    {
        $command = $this->stringField($event, 'command');

        return $command !== null && Str::matchesAny($command, $this->patterns('collectors.commands.ignore'))
            ? null
            : $event;
    }

    /**
     * @return list<string>
     */
    private function patterns(string $key): array
    {
        /** @var list<string> $patterns */
        $patterns = $this->config->array($key);

        return $patterns;
    }

    private function stringField(Event $event, string $field): ?string
    {
        $value = $event->payload[$field] ?? null;

        return is_string($value) ? $value : null;
    }
}
