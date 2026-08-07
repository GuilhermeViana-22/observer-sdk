<?php

declare(strict_types=1);

namespace Observer\Contracts;

use Observer\DTO\Event;

/**
 * Estágio da pipeline de processamento de eventos.
 */
interface EventProcessor
{
    /**
     * Retorna o evento (possivelmente modificado) ou null para descartá-lo.
     */
    public function process(Event $event): ?Event;
}
