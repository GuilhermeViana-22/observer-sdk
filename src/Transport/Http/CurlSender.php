<?php

declare(strict_types=1);

namespace Observer\Transport\Http;

use Observer\Contracts\HttpSender;

/**
 * Envio via ext-curl — o mecanismo preferido.
 *
 * É o único disponível no PHP que separa o timeout de CONEXÃO do timeout TOTAL
 * (CURLOPT_CONNECTTIMEOUT_MS e CURLOPT_TIMEOUT_MS). Essa distinção é a razão de
 * existirem `connect_timeout: 1.0` e `timeout: 2.0` na configuração: um
 * servidor inalcançável precisa falhar rápido, enquanto um servidor lento
 * merece um pouco mais de paciência. Com os wrappers de stream isso não é
 * expressável.
 */
final class CurlSender implements HttpSender
{
    public static function available(): bool
    {
        return function_exists('curl_init');
    }

    public function send(
        string $url,
        string $body,
        array $headers,
        float $timeout,
        float $connectTimeout,
    ): HttpResponse {
        $handle = curl_init($url);

        if ($handle === false) {
            return HttpResponse::transportError('curl_init falhou');
        }

        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = "{$name}: {$value}";
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $formatted,
            CURLOPT_RETURNTRANSFER => true,

            // Sem FAILONERROR: precisamos LER o corpo de um 4xx para registrar o
            // motivo. Com ele ligado, o curl descartaria a resposta de erro.
            CURLOPT_FAILONERROR => false,

            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($connectTimeout * 1000),

            // O SDK roda dentro do request da aplicação do cliente: seguir
            // redirecionamento multiplicaria o tempo gasto sem nenhum ganho.
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        // SEM curl_close(): desde o PHP 8.0 o curl_init() devolve um objeto
        // CurlHandle em vez de resource, a função virou no-op, e o PHP 8.5 a
        // depreca. O handle é liberado pelo coletor de lixo quando $handle sai
        // de escopo, no fim deste método.
        //
        // Chamá-la não fechava nada e ainda emitia um deprecated A CADA ENVIO —
        // que o logger da aplicação capturava e o LogCollector transformava em
        // evento, exigindo mais um envio. Uma linha sem efeito nenhum
        // sustentando um laço de ruído cobrado ao cliente.

        if ($response === false || $status === 0) {
            return HttpResponse::transportError($error !== '' ? $error : 'falha de transporte');
        }

        return new HttpResponse(status: $status, body: is_string($response) ? $response : '');
    }
}
