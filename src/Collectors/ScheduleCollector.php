<?php

declare(strict_types=1);

namespace Observer\Collectors;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledTask;
use Observer\DTO\Payloads\ScheduledTaskPayload;
use Observer\Enums\EventType;
use Observer\Enums\Severity;
use Observer\Support\Str;

/**
 * Captura a execução das tarefas agendadas.
 *
 * Responde a duas perguntas que o cron não responde: "a task rodou?" e
 * "quanto tempo levou?". O evento 'skipped' é tão importante quanto o
 * 'failed' — uma task que nunca roda por causa de um withoutOverlapping
 * travado é uma falha silenciosa clássica.
 */
final class ScheduleCollector extends AbstractCollector
{
    private const OUTPUT_LIMIT = 4000;

    public function name(): string
    {
        return 'schedule';
    }

    public function register(): void
    {
        $this->listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event): void {
            $this->push($event->task, ScheduledTaskPayload::STATUS_STARTED, Severity::Debug);
        });

        $this->listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            $this->push(
                $event->task,
                ScheduledTaskPayload::STATUS_FINISHED,
                Severity::Info,
                durationMs: $event->runtime * 1000,
                exitCode: $event->task->exitCode,
                output: $this->output($event->task),
            );

            $this->observer()->flush();
        });

        $this->listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event): void {
            $this->push($event->task, ScheduledTaskPayload::STATUS_SKIPPED, Severity::Notice);
        });

        $this->listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            $observer = $this->client();

            $this->push(
                $event->task,
                ScheduledTaskPayload::STATUS_FAILED,
                Severity::Error,
                exitCode: $event->task->exitCode,
                output: $this->output($event->task),
                error: $event->exception->getMessage(),
            );

            if ($observer !== null) {
                $observer->capture($event->exception, ['task' => $event->task->getSummaryForDisplay()], handled: false);
            }

            $this->observer()->flush();
        });
    }

    private function push(
        ScheduledTask $task,
        string $status,
        Severity $level,
        ?float $durationMs = null,
        ?int $exitCode = null,
        ?string $output = null,
        ?string $error = null,
    ): void {
        $summary = $task->getSummaryForDisplay();

        $this->record(
            EventType::Schedule,
            "schedule.{$status} {$summary}",
            new ScheduledTaskPayload(
                task: $summary,
                status: $status,
                expression: $task->getExpression(),
                description: $task->description,
                durationMs: $durationMs,
                exitCode: $exitCode,
                output: $output,
                error: $error,
            ),
            $level,
        );
    }

    /**
     * A saída só existe quando a task foi configurada com sendOutputTo()/
     * appendOutputTo(); caso contrário o arquivo aponta para /dev/null.
     */
    private function output(ScheduledTask $task): ?string
    {
        if (! $this->boolOption('capture_output', true)) {
            return null;
        }

        $path = $task->output;

        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        return is_string($content) && $content !== ''
            ? Str::truncate($content, self::OUTPUT_LIMIT)
            : null;
    }
}
