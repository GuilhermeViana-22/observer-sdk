<?php

declare(strict_types=1);

namespace Observer\Pipeline;

use Observer\Contracts\EventProcessor;
use Observer\DTO\Event;
use Observer\Support\InternalLogger;

/**
 * Cadeia de responsabilidade aplicada a todo evento antes do buffer.
 *
 * Curto-circuita no primeiro processor que retornar null (evento descartado)
 * e conta os descartes por processor — informação valiosa para diagnosticar
 * "por que meu evento não apareceu no dashboard".
 */
final class EventPipeline
{
    /** @var list<EventProcessor> */
    private array $processors = [];

    /** @var array<string, int> */
    private array $drops = [];

    /**
     * @param list<EventProcessor> $processors
     */
    public function __construct(array $processors = [], private readonly ?InternalLogger $logger = null)
    {
        foreach ($processors as $processor) {
            $this->pipe($processor);
        }
    }

    public function pipe(EventProcessor $processor): self
    {
        $this->processors[] = $processor;

        return $this;
    }

    public function process(Event $event): ?Event
    {
        foreach ($this->processors as $processor) {
            try {
                $result = $processor->process($event);
            } catch (\Throwable $e) {
                // Um processor quebrado não pode derrubar a coleta:
                // segue com o evento como estava antes dele.
                $this->logger?->report($e, 'pipeline:'.$processor::class);

                continue;
            }

            if ($result === null) {
                $this->recordDrop($processor::class);

                return null;
            }

            $event = $result;
        }

        return $event;
    }

    /**
     * @return list<EventProcessor>
     */
    public function processors(): array
    {
        return $this->processors;
    }

    /**
     * @return array<string, int>
     */
    public function drops(): array
    {
        return $this->drops;
    }

    private function recordDrop(string $processor): void
    {
        $this->drops[$processor] = ($this->drops[$processor] ?? 0) + 1;
    }
}
