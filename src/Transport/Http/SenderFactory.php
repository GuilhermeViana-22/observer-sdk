<?php

declare(strict_types=1);

namespace Observer\Transport\Http;

use Observer\Contracts\HttpSender;

/**
 * Escolhe o mecanismo de envio disponível no ambiente.
 *
 * A ordem não é arbitrária: curl primeiro por causa do timeout de conexão
 * separado; streams como degradação aceitável; e, se nem isso existir, um
 * sender que falha silenciosamente — porque um SDK de observabilidade jamais
 * pode derrubar a aplicação que ele observa, nem mesmo no boot.
 */
final class SenderFactory
{
    public static function detect(): HttpSender
    {
        if (CurlSender::available()) {
            return new CurlSender;
        }

        if (StreamSender::available()) {
            return new StreamSender;
        }

        return new UnavailableSender;
    }
}
