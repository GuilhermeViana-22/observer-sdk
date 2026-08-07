<?php

declare(strict_types=1);

namespace Observer;

use Observer\Buffer\EventBuffer;
use Observer\Contracts\ClientInterface;
use Observer\Contracts\Payload;
use Observer\DTO\Event;
use Observer\DTO\Payloads\ExceptionPayload;
use Observer\DTO\Payloads\LogPayload;
use Observer\DTO\Payloads\MetricPayload;
use Observer\Enums\EventType;
use Observer\Enums\Severity;
use Observer\Pipeline\EventPipeline;
use Observer\Services\ExceptionFormatter;
use Observer\Services\ScopeManager;
use Observer\Support\Configuration;
use Observer\Support\InternalLogger;
use Throwable;

/**
 * Núcleo do SDK e implementação da API pública.
 *
 * Responsabilidade única: transformar chamadas da aplicação em eventos,
 * empurrá-los pela pipeline e entregá-los ao buffer. Não conhece transporte,
 * não conhece Laravel, não conhece formato de fio.
 */
final class Observer implements ClientInterface
{
    public const VERSION = '1.0.0';

    public function __construct(
        private readonly Configuration $config,
        private readonly EventPipeline $pipeline,
        private readonly EventBuffer $buffer,
        private readonly ScopeManager $scope,
        private readonly ExceptionFormatter $formatter,
        private readonly InternalLogger $logger,
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function capture(Throwable $exception, array $context = [], bool $handled = true): ?string
    {
        return $this->dispatch(
            EventType::Exception,
            $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class,
            $this->formatter->format($exception, $handled),
            Severity::Error,
            $context,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string|Severity $level, string $message, array $context = []): ?string
    {
        $severity = Severity::fromPsrLevel($level);

        if (! $severity->isAtLeast($this->config->minimumLogLevel())) {
            return null;
        }

        return $this->dispatch(
            EventType::Log,
            $message,
            new LogPayload(message: $message, level: $severity->value, context: $context),
            $severity,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    public function event(string $name, array $payload = [], array $context = []): ?string
    {
        return $this->dispatch(
            EventType::Custom,
            $name,
            ['name' => $name, 'data' => $payload],
            Severity::Info,
            $context,
        );
    }

    /**
     * @param array<string, scalar> $tags
     */
    public function metric(
        string $name,
        float $value,
        array $tags = [],
        string $type = MetricPayload::TYPE_GAUGE,
        ?string $unit = null,
    ): ?string {
        return $this->dispatch(
            EventType::Metric,
            $name,
            new MetricPayload(name: $name, value: $value, metricType: $type, unit: $unit, tags: $tags),
            Severity::Info,
        );
    }

    /**
     * Porta de entrada dos collectors: o evento já vem montado.
     */
    public function record(Event $event): ?string
    {
        return $this->logger->safely(function () use ($event): ?string {
            $processed = $this->pipeline->process($event);

            if ($processed === null) {
                return null;
            }

            $this->buffer->add($processed);

            return $processed->id;
        }, 'record');
    }

    public function flush(): bool
    {
        return $this->logger->safely(fn (): bool => $this->buffer->flush(), 'flush') ?? false;
    }

    // ---------------------------------------------------------------------
    // Escopo
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $user
     */
    public function withUser(array $user): self
    {
        $this->scope->setUser($user);

        return $this;
    }

    /**
     * @param array<string, scalar> $tags
     */
    public function withTags(array $tags): self
    {
        $this->scope->addTags($tags);

        return $this;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function withExtra(array $extra): self
    {
        $this->scope->addExtra($extra);

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function breadcrumb(string $category, string $message, array $data = [], Severity $level = Severity::Info): self
    {
        $this->scope->addBreadcrumb($category, $message, $data, $level);

        return $this;
    }

    /**
     * Reinicia o escopo. Chamado entre jobs e entre requests no Octane, onde
     * o processo é reaproveitado e o contexto vazaria de uma execução à outra.
     */
    public function resetScope(): void
    {
        $this->scope->clear();
    }

    public function scope(): ScopeManager
    {
        return $this->scope;
    }

    public function buffer(): EventBuffer
    {
        return $this->buffer;
    }

    public function config(): Configuration
    {
        return $this->config;
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    /**
     * Formata um Throwable sem registrá-lo — usado por collectors que
     * embutem a exception em outro payload (job falho, task falha).
     */
    public function formatException(Throwable $exception, bool $handled = true): ExceptionPayload
    {
        return $this->formatter->format($exception, $handled);
    }

    /**
     * @param array<string, mixed>|Payload $payload
     * @param array<string, mixed> $context
     */
    private function dispatch(
        EventType $type,
        string $message,
        array|Payload $payload,
        Severity $level,
        array $context = [],
    ): ?string {
        // Portão barato antes de qualquer alocação: SDK desligado é no-op.
        if (! $this->config->isEnabled()) {
            return null;
        }

        $event = Event::make($type, $message, $payload, $level);

        if ($context !== []) {
            $event = $event->mergeContext(['extra' => $context]);
        }

        return $this->record($event);
    }
}
