<?php

declare(strict_types=1);

namespace Observer\Contracts;

use Observer\DTO\Event;
use Observer\Enums\Severity;
use Throwable;

/**
 * A API pública do SDK.
 *
 * Depender desta interface (em vez da facade) mantém o código do usuário
 * testável e desacoplado da implementação concreta.
 */
interface ClientInterface
{
    /**
     * @param array<string, mixed> $context
     * @return string|null ID do evento, ou null se descartado
     */
    public function capture(Throwable $exception, array $context = []): ?string;

    /**
     * @param array<string, mixed> $context
     */
    public function log(string|Severity $level, string $message, array $context = []): ?string;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    public function event(string $name, array $payload = [], array $context = []): ?string;

    /**
     * @param array<string, scalar> $tags
     */
    public function metric(string $name, float $value, array $tags = []): ?string;

    /**
     * Porta de entrada usada pelos collectors para eventos já montados.
     */
    public function record(Event $event): ?string;

    /**
     * Drena o buffer para o transporte.
     */
    public function flush(): bool;
}
