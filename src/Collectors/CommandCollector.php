<?php

declare(strict_types=1);

namespace Observer\Collectors;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Observer\DTO\Payloads\CommandPayload;
use Observer\Enums\EventType;
use Observer\Enums\Severity;

/**
 * Captura comandos artisan.
 *
 * Desligado por padrão: em uma app com scheduler ativo, `schedule:run` a cada
 * minuto vira ruído. Útil quando comandos de negócio (importações, fechamentos)
 * precisam de acompanhamento.
 */
final class CommandCollector extends AbstractCollector
{
    /** @var array<string, float> */
    private array $timers = [];

    public function name(): string
    {
        return 'commands';
    }

    public function register(): void
    {
        $this->listen(CommandStarting::class, function (CommandStarting $event): void {
            $command = $event->command ?? 'artisan';
            $this->timers[$command] = microtime(true);

            $this->record(
                EventType::Command,
                "command.started {$command}",
                new CommandPayload(
                    command: $command,
                    status: CommandPayload::STATUS_STARTED,
                    arguments: $event->input->getArguments(),
                    options: array_filter($event->input->getOptions()),
                ),
                Severity::Debug,
            );
        });

        $this->listen(CommandFinished::class, function (CommandFinished $event): void {
            $command = $event->command ?? 'artisan';
            $startedAt = $this->timers[$command] ?? null;
            unset($this->timers[$command]);

            $this->record(
                EventType::Command,
                "command.finished {$command}",
                new CommandPayload(
                    command: $command,
                    status: CommandPayload::STATUS_FINISHED,
                    exitCode: $event->exitCode,
                    durationMs: $startedAt === null ? null : (microtime(true) - $startedAt) * 1000,
                ),
                $event->exitCode === 0 ? Severity::Info : Severity::Error,
            );

            $this->observer()->flush();
        });
    }
}
