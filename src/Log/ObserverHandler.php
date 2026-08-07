<?php

declare(strict_types=1);

namespace Observer\Log;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Observer\Contracts\ClientInterface;
use Observer\DTO\Event;
use Observer\DTO\Payloads\LogPayload;
use Observer\Enums\EventType;
use Observer\Enums\Severity;

/**
 * Handler Monolog do Observer.
 *
 * Alternativa explícita ao LogCollector: quem quiser enviar apenas um canal
 * específico (e não tudo que passa pelo logger) declara o canal 'observer' no
 * config/logging.php e usa Log::channel('observer').
 *
 * Os dois caminhos coexistem — mas ligar ambos no mesmo canal duplica eventos.
 */
final class ObserverHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly ClientInterface $observer,
        Level|int|string $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $severity = Severity::fromMonologLevel($record->level->value);

        $this->observer->record(Event::make(
            EventType::Log,
            $record->message,
            new LogPayload(
                message: $record->message,
                level: $severity->value,
                channel: $record->channel,
                context: $record->context,
                extra: $record->extra,
            ),
            $severity,
        ));
    }
}
