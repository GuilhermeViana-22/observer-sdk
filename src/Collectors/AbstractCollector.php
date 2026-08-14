<?php

declare(strict_types=1);

namespace Observer\Collectors;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Observer\Contracts\ClientInterface;
use Observer\Contracts\Collector;
use Observer\Contracts\Payload;
use Observer\DTO\Event;
use Observer\Enums\EventType;
use Observer\Enums\Severity;
use Observer\Observer;
use Observer\Support\Configuration;
use Observer\Support\InternalLogger;
use Observer\Support\SelfGuard;
use Throwable;

/**
 * Base dos collectors.
 *
 * Concentra as garantias que todo collector precisa oferecer:
 *
 *  1. Falha isolada — uma exceção durante a coleta nunca sobe para a aplicação.
 *  2. Guard anti-recursão — eventos gerados pelo próprio SDK (a query que o
 *     FileTransport dispara, o log que o SDK emite) não são coletados, o que
 *     evitaria um laço infinito.
 *  3. Deduplicação de exceptions entre collectors.
 *  4. Acesso tipado às opções do próprio collector.
 *
 * Cliente e configuração são resolvidos do container a cada uso, e não
 * capturados no construtor: os listeners são registrados uma única vez no
 * boot, mas o container pode ser reconfigurado depois (Observer::fake(),
 * config recarregada no Octane). Segurar a instância deixaria o collector
 * escrevendo em um transporte obsoleto.
 */
abstract class AbstractCollector implements Collector
{
    /** @var array<string, true> */
    private static array $seenExceptions = [];

    public function __construct(
        protected readonly Container $container,
        protected readonly Dispatcher $events,
        protected readonly InternalLogger $logger,
    ) {}

    public function isEnabled(): bool
    {
        return $this->config()->collectorEnabled($this->name());
    }

    protected function observer(): ClientInterface
    {
        /** @var ClientInterface $client */
        $client = $this->container->make(ClientInterface::class);

        return $client;
    }

    /**
     * Cliente concreto, quando o collector precisa da API estendida
     * (formatException, escopo). Null se alguém trocou a implementação.
     */
    protected function client(): ?Observer
    {
        $client = $this->observer();

        return $client instanceof Observer ? $client : null;
    }

    protected function config(): Configuration
    {
        /** @var Configuration $config */
        $config = $this->container->make(Configuration::class);

        return $config;
    }

    /**
     * Assina um evento do Laravel isolando qualquer falha do handler.
     *
     * @param class-string|string $event
     * @param callable(object): void $handler
     */
    protected function listen(string $event, callable $handler): void
    {
        $this->events->listen($event, function (object $payload) use ($handler, $event): void {
            // O SDK é o meio, não o fim: nada que aconteça dentro dele —
            // registrando OU enviando — pode virar evento. Ver SelfGuard.
            if (SelfGuard::active()) {
                return;
            }

            $this->logger->safely(static fn () => $handler($payload), $event);
        });
    }

    /**
     * @param array<string, mixed>|Payload $payload
     */
    protected function record(
        EventType $type,
        string $message,
        array|Payload $payload,
        Severity $level = Severity::Info,
    ): void {
        $this->emit(Event::make($type, $message, $payload, $level));
    }

    protected function emit(Event $event): void
    {
        if (SelfGuard::active()) {
            return;
        }

        SelfGuard::run(fn () => $this->observer()->record($event));
    }

    protected function option(string $key, mixed $default = null): mixed
    {
        return $this->config()->get("collectors.{$this->name()}.{$key}", $default);
    }

    protected function boolOption(string $key, bool $default = false): bool
    {
        return filter_var($this->option($key, $default), FILTER_VALIDATE_BOOL);
    }

    protected function floatOption(string $key, float $default = 0.0): float
    {
        $value = $this->option($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    protected function intOption(string $key, int $default = 0): int
    {
        $value = $this->option($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return list<string>
     */
    protected function arrayOption(string $key): array
    {
        $value = $this->option($key, []);

        /** @var list<string> $value */
        return is_array($value) ? $value : [];
    }

    protected static function isRecording(): bool
    {
        return SelfGuard::active();
    }

    /**
     * Deduplicação de exceptions compartilhada entre collectors.
     *
     * A mesma exception costuma chegar por dois caminhos — o report() do
     * handler e o Log::error(..., ['exception' => $e]) que ele emite. O mapa
     * é estático justamente para que o segundo caminho reconheça o primeiro.
     *
     * A identidade é a do objeto: duas ocorrências distintas do mesmo erro
     * continuam sendo dois eventos.
     */
    protected function isDuplicateException(Throwable $exception): bool
    {
        $hash = spl_object_hash($exception);

        if (isset(self::$seenExceptions[$hash])) {
            return true;
        }

        // Teto de memória: em um worker de fila longevo, o mapa não pode crescer.
        if (count(self::$seenExceptions) > 100) {
            self::$seenExceptions = [];
        }

        self::$seenExceptions[$hash] = true;

        return false;
    }

    public static function forgetSeenExceptions(): void
    {
        self::$seenExceptions = [];
    }
}
