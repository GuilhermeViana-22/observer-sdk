<?php

declare(strict_types=1);

namespace Observer\Contracts;

use Observer\Transport\Http\HttpResponse;

/**
 * Mecanismo de envio HTTP, isolado do transporte.
 *
 * Existe por duas razões concretas:
 *
 * 1. O SDK não declara nenhum cliente HTTP como dependência. Exigir Guzzle no
 *    "require" imporia uma restrição de versão a TODA aplicação que instalar o
 *    Observer — o próprio Laravel apenas o sugere. Com esta interface, usamos
 *    ext-curl quando existe e caímos para os wrappers nativos quando não.
 *
 * 2. Torna o transporte testável sem rede: os testes injetam um sender falso e
 *    afirmam status, retry e cabeçalhos sem subir servidor nenhum.
 */
interface HttpSender
{
    /**
     * @param array<string, string> $headers
     */
    public function send(
        string $url,
        string $body,
        array $headers,
        float $timeout,
        float $connectTimeout,
    ): HttpResponse;
}
