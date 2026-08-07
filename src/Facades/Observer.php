<?php

declare(strict_types=1);

namespace Observer\Facades;

use Illuminate\Support\Facades\Facade;
use Observer\Contracts\ClientInterface;
use Observer\Testing\ObserverFake;
use Observer\Transport\MemoryTransport;

/**
 * Ponto de entrada elegante para a aplicação.
 *
 * Prefira injetar ClientInterface no construtor quando possível — a facade
 * existe para conveniência em código legado, closures e helpers.
 *
 * @method static string|null capture(\Throwable $exception, array $context = [], bool $handled = true)
 * @method static string|null log(string|\Observer\Enums\Severity $level, string $message, array $context = [])
 * @method static string|null event(string $name, array $payload = [], array $context = [])
 * @method static string|null metric(string $name, float $value, array $tags = [], string $type = 'gauge', ?string $unit = null)
 * @method static string|null record(\Observer\DTO\Event $event)
 * @method static bool flush()
 * @method static \Observer\Observer withUser(array $user)
 * @method static \Observer\Observer withTags(array $tags)
 * @method static \Observer\Observer withExtra(array $extra)
 * @method static \Observer\Observer breadcrumb(string $category, string $message, array $data = [], \Observer\Enums\Severity $level = \Observer\Enums\Severity::Info)
 * @method static void resetScope()
 * @method static \Observer\Services\ScopeManager scope()
 * @method static \Observer\Buffer\EventBuffer buffer()
 * @method static \Observer\Support\Configuration config()
 * @method static bool isEnabled()
 * @method static \Observer\DTO\Payloads\ExceptionPayload formatException(\Throwable $exception, bool $handled = true)
 *
 * @see \Observer\Observer
 */
final class Observer extends Facade
{
    /**
     * Troca o transporte por um MemoryTransport e devolve o helper de asserts.
     *
     * Observer::fake();
     * // ...
     * Observer::assert()->assertCaptured(RuntimeException::class);
     */
    public static function fake(): ObserverFake
    {
        return ObserverFake::swap();
    }

    /**
     * Helper de asserts do fake ativo.
     */
    public static function assert(): ObserverFake
    {
        return ObserverFake::instance();
    }

    /**
     * Transporte em memória do fake ativo.
     */
    public static function recorded(): MemoryTransport
    {
        return ObserverFake::instance()->transport();
    }

    protected static function getFacadeAccessor(): string
    {
        return ClientInterface::class;
    }
}
