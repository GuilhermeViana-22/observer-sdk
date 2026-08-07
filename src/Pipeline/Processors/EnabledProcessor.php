<?php

declare(strict_types=1);

namespace Observer\Pipeline\Processors;

use Observer\Contracts\EventProcessor;
use Observer\DTO\Event;
use Observer\Support\Configuration;

/**
 * Primeiro portão: SDK ligado, ambiente permitido e collector do tipo ativo.
 *
 * Fica no topo da pipeline porque é o teste mais barato e o que descarta mais.
 */
final class EnabledProcessor implements EventProcessor
{
    public function __construct(private readonly Configuration $config) {}

    public function process(Event $event): ?Event
    {
        if (! $this->config->isEnabled()) {
            return null;
        }

        $collector = $event->type->configKey();

        // Tipos sem collector correspondente (custom, metric) passam direto.
        if ($this->config->get("collectors.{$collector}") === null) {
            return $event;
        }

        return $this->config->collectorEnabled($collector) ? $event : null;
    }
}
