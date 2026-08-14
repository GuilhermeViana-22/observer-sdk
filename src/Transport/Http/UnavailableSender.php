<?php

declare(strict_types=1);

namespace Observer\Transport\Http;

use Observer\Contracts\HttpSender;

/**
 * Sender usado quando o ambiente não tem nem ext-curl nem allow_url_fopen.
 *
 * Devolve uma falha PERMANENTE de propósito: se retornasse algo retriável, cada
 * flush gastaria o orçamento inteiro de tentativas para descobrir de novo o que
 * já sabemos — que não há como enviar neste ambiente.
 */
final class UnavailableSender implements HttpSender
{
    public function send(
        string $url,
        string $body,
        array $headers,
        float $timeout,
        float $connectTimeout,
    ): HttpResponse {
        return new HttpResponse(
            status: 400,
            error: 'nenhum mecanismo HTTP disponível: instale ext-curl ou habilite allow_url_fopen',
        );
    }
}
