<?php

declare(strict_types=1);

namespace Observer\Collectors;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Observer\DTO\Payloads\QueuePayload;
use Observer\Enums\EventType;
use Observer\Enums\Severity;

/**
 * Captura o ciclo de vida dos jobs.
 *
 * Por padrão só registra o desfecho (processed/failed): enfileiramento e
 * início dobram o volume sem acrescentar muita informação. Ambos podem ser
 * ligados pela configuração.
 *
 * Também limpa o escopo entre jobs — em um worker o processo é reaproveitado,
 * e o usuário/tags de um job vazariam para o próximo.
 */
final class QueueCollector extends AbstractCollector
{
    /** @var array<string, float> */
    private array $timers = [];

    public function name(): string
    {
        return 'queue';
    }

    public function register(): void
    {
        $this->listen(JobQueued::class, function (JobQueued $event): void {
            if (! $this->boolOption('capture_queued', false)) {
                return;
            }

            $this->push(new QueuePayload(
                job: $this->jobName($event->job),
                status: QueuePayload::STATUS_QUEUED,
                queue: $event->queue,
                connection: $event->connectionName,
                jobId: is_scalar($event->id) ? (string) $event->id : null,
            ), Severity::Debug);
        });

        $this->listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->timers[$this->key($event->job)] = microtime(true);

            $observer = $this->client();

            // Novo job, novo escopo: nada do job anterior deve sobrar.
            if ($observer !== null) {
                $observer->resetScope();
                $observer->withTags(['job' => $event->job->resolveName(), 'queue' => $event->job->getQueue()]);
            }

            if ($this->boolOption('capture_processing', false)) {
                $this->push(new QueuePayload(
                    job: $event->job->resolveName(),
                    status: QueuePayload::STATUS_PROCESSING,
                    queue: $event->job->getQueue(),
                    connection: $event->connectionName,
                    jobId: $event->job->getJobId(),
                    attempts: $event->job->attempts(),
                ), Severity::Debug);
            }
        });

        $this->listen(JobProcessed::class, function (JobProcessed $event): void {
            $this->push(new QueuePayload(
                job: $event->job->resolveName(),
                status: QueuePayload::STATUS_PROCESSED,
                queue: $event->job->getQueue(),
                connection: $event->connectionName,
                jobId: $event->job->getJobId(),
                attempts: $event->job->attempts(),
                durationMs: $this->elapsed($event->job),
            ), Severity::Info);

            // O worker segue vivo: garante a entrega do que foi coletado.
            $this->observer()->flush();
        });

        $this->listen(JobFailed::class, function (JobFailed $event): void {
            $observer = $this->client();

            $this->push(new QueuePayload(
                job: $event->job->resolveName(),
                status: QueuePayload::STATUS_FAILED,
                queue: $event->job->getQueue(),
                connection: $event->connectionName,
                jobId: $event->job->getJobId(),
                attempts: $event->job->attempts(),
                durationMs: $this->elapsed($event->job),
                exception: $observer !== null
                    ? $observer->formatException($event->exception, handled: false)->toArray()
                    : ['class' => $event->exception::class, 'message' => $event->exception->getMessage()],
            ), Severity::Error);

            $this->observer()->flush();
        });
    }

    private function push(QueuePayload $payload, Severity $level): void
    {
        $this->record(
            EventType::Queue,
            sprintf('%s %s', $payload->status, $payload->job),
            $payload,
            $level,
        );
    }

    private function elapsed(object $job): ?float
    {
        $key = $this->key($job);
        $startedAt = $this->timers[$key] ?? null;

        unset($this->timers[$key]);

        return $startedAt === null ? null : (microtime(true) - $startedAt) * 1000;
    }

    private function key(object $job): string
    {
        return spl_object_hash($job);
    }

    private function jobName(object|string $job): string
    {
        return is_string($job) ? $job : $job::class;
    }
}
