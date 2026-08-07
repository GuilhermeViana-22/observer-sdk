<?php

declare(strict_types=1);

namespace Observer\Contracts;

/**
 * Payload tipado de um domínio específico (exception, query, request…).
 *
 * Collectors montam um Payload — não um array solto — para que erros de
 * campo apareçam em análise estática e não em produção.
 */
interface Payload
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
