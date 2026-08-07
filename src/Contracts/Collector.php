<?php

declare(strict_types=1);

namespace Observer\Contracts;

/**
 * Adaptador entre um sinal do Laravel e um evento do Observer.
 *
 * Implementações devem ser idempotentes: register() pode ser chamado
 * apenas uma vez por ciclo de vida da aplicação.
 */
interface Collector
{
    public function name(): string;

    /**
     * Assina os hooks do framework. Nunca deve lançar exceção.
     */
    public function register(): void;
}
