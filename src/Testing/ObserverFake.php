<?php

declare(strict_types=1);

namespace Observer\Testing;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Observer\Buffer\EventBuffer;
use Observer\Contracts\ClientInterface;
use Observer\DTO\Event;
use Observer\Enums\EventType;
use Observer\Exceptions\ObserverException;
use Observer\Observer;
use Observer\Pipeline\EventPipeline;
use Observer\Pipeline\Processors\BeforeSendProcessor;
use Observer\Pipeline\Processors\ScrubbingProcessor;
use Observer\Services\ExceptionFormatter;
use Observer\Services\Redactor;
use Observer\Support\Configuration;
use Observer\Support\InternalLogger;
use Observer\Transport\MemoryTransport;
use Observer\Transport\TransportManager;
use PHPUnit\Framework\Assert;

/**
 * Dublê de teste do SDK.
 *
 * Troca o transporte por MemoryTransport e desliga o buffer, para que cada
 * evento fique imediatamente disponível para asserts — sem disco, sem rede
 * e sem precisar chamar flush() no teste.
 */
final class ObserverFake
{
    /** @var list<class-string|string> */
    private const SINGLETONS = [
        Configuration::class,
        InternalLogger::class,
        Redactor::class,
        ExceptionFormatter::class,
        ScrubbingProcessor::class,
        BeforeSendProcessor::class,
        EventPipeline::class,
        TransportManager::class,
        EventBuffer::class,
        Observer::class,
        ClientInterface::class,
    ];

    private static ?self $instance = null;

    public function __construct(private readonly MemoryTransport $transport) {}

    public static function swap(?Container $app = null): self
    {
        $app ??= Container::getInstance();

        $config = $app->make('config');
        $config->set('observer.enabled', true);
        $config->set('observer.transport.driver', 'memory');
        // Sem buffer, o assert enxerga o evento no instante em que é gerado.
        $config->set('observer.buffer.enabled', false);
        $config->set('observer.sample_rate', 1.0);
        $config->set('observer.sample_rates', []);

        // Rebuild completo: todo singleton do SDK carrega config resolvida no
        // momento em que foi construído, então esquecê-los é o que faz uma
        // alteração de config feita no teste valer de fato.
        foreach (self::SINGLETONS as $abstract) {
            $app->forgetInstance($abstract);
        }

        Facade::clearResolvedInstance(ClientInterface::class);

        $transport = $app->make(TransportManager::class)->driver();

        if (! $transport instanceof MemoryTransport) {
            throw new ObserverException('Observer::fake() esperava um MemoryTransport.');
        }

        return self::$instance = new self($transport);
    }

    public static function instance(): self
    {
        return self::$instance ?? throw new ObserverException(
            'Nenhum fake ativo. Chame Observer::fake() antes de Observer::assert().'
        );
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function transport(): MemoryTransport
    {
        return $this->transport;
    }

    /**
     * @return list<Event>
     */
    public function events(?EventType $type = null): array
    {
        return $this->transport->events($type);
    }

    /**
     * @param (callable(Event): bool)|null $filter
     */
    public function assertRecorded(EventType $type, ?callable $filter = null, ?int $times = null): self
    {
        $matching = array_filter(
            $this->transport->events($type),
            static fn (Event $e): bool => $filter === null || $filter($e),
        );

        if ($times !== null) {
            Assert::assertCount($times, $matching, "Esperava {$times} evento(s) do tipo [{$type->value}].");

            return $this;
        }

        Assert::assertNotEmpty($matching, "Nenhum evento do tipo [{$type->value}] foi registrado.");

        return $this;
    }

    public function assertCaptured(string $exceptionClass, ?int $times = null): self
    {
        return $this->assertRecorded(
            EventType::Exception,
            static fn (Event $e): bool => ($e->payload['class'] ?? null) === $exceptionClass,
            $times,
        );
    }

    public function assertLogged(string $message): self
    {
        return $this->assertRecorded(
            EventType::Log,
            static fn (Event $e): bool => str_contains($e->message, $message),
        );
    }

    public function assertEvent(string $name): self
    {
        return $this->assertRecorded(
            EventType::Custom,
            static fn (Event $e): bool => $e->message === $name,
        );
    }

    public function assertMetric(string $name, ?float $value = null): self
    {
        return $this->assertRecorded(
            EventType::Metric,
            static fn (Event $e): bool => ($e->payload['name'] ?? null) === $name
                && ($value === null || ($e->payload['value'] ?? null) === $value),
        );
    }

    public function assertNothingRecorded(?EventType $type = null): self
    {
        Assert::assertSame(0, $this->transport->count($type), 'Esperava nenhum evento registrado.');

        return $this;
    }

    public function assertCount(int $expected, ?EventType $type = null): self
    {
        Assert::assertSame($expected, $this->transport->count($type));

        return $this;
    }

    public function clear(): self
    {
        $this->transport->clear();

        return $this;
    }
}
