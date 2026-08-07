<?php

declare(strict_types=1);

namespace Observer\Pipeline\Processors;

use Observer\Contracts\EventProcessor;
use Observer\DTO\Event;
use Observer\Enums\EventType;
use Observer\Enums\Severity;
use Observer\Support\Configuration;

/**
 * Amostragem probabilística por tipo de evento.
 *
 * Regra de negócio deliberada: exceptions e eventos de nível >= error nunca
 * são amostrados. Perder 90% das queries é aceitável; perder um erro de
 * produção não é.
 */
final class SamplingProcessor implements EventProcessor
{
    public function __construct(private readonly Configuration $config) {}

    public function process(Event $event): ?Event
    {
        if ($this->isAlwaysSampled($event)) {
            return $event;
        }

        $rate = $this->config->sampleRateFor($event->type);

        if ($rate >= 1.0) {
            return $event;
        }

        if ($rate <= 0.0) {
            return null;
        }

        return (mt_rand() / mt_getrandmax()) <= $rate ? $event : null;
    }

    private function isAlwaysSampled(Event $event): bool
    {
        return $event->type === EventType::Exception
            || $event->level->isAtLeast(Severity::Error);
    }
}
