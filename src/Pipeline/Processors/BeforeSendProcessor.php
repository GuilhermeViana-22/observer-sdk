<?php

declare(strict_types=1);

namespace Observer\Pipeline\Processors;

use Observer\Contracts\EventProcessor;
use Observer\DTO\Event;

/**
 * Último ponto de controle do desenvolvedor antes do buffer.
 *
 * O callback recebe o evento já enriquecido e mascarado, e devolve o evento
 * (modificado ou não) ou null para descartá-lo. Um retorno inválido é tratado
 * como "não mexa" — nunca como descarte, para não perder eventos por engano.
 */
final class BeforeSendProcessor implements EventProcessor
{
    /** @var (callable(Event): (Event|null))|null */
    private $callback;

    /**
     * @param (callable(Event): (Event|null))|null $callback
     */
    public function __construct(?callable $callback)
    {
        $this->callback = $callback;
    }

    public function process(Event $event): ?Event
    {
        if ($this->callback === null) {
            return $event;
        }

        $result = ($this->callback)($event);

        if ($result === null) {
            return null;
        }

        return $result instanceof Event ? $result : $event;
    }
}
