<?php

declare(strict_types=1);

namespace Observer\Transport\Http;

use Observer\Contracts\HttpSender;

/**
 * Envio pelos wrappers HTTP nativos do PHP — o fallback.
 *
 * Não é o padrão porque tem duas limitações reais:
 *
 * 1. O `timeout` do contexto HTTP é apenas de LEITURA. O tempo de conexão cai
 *    no default_socket_timeout do php.ini, que costuma ser 60 s — uma
 *    eternidade dentro do ciclo de request de uma aplicação web. Aplicamos o
 *    valor localmente com ini_set para reduzir o estrago, mas o controle é
 *    grosseiro comparado ao do curl.
 *
 * 2. Depende de allow_url_fopen, desligado em muita hospedagem endurecida.
 *
 * Ainda assim é melhor que não enviar: existem ambientes sem ext-curl.
 */
final class StreamSender implements HttpSender
{
    public static function available(): bool
    {
        return filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL);
    }

    public function send(
        string $url,
        string $body,
        array $headers,
        float $timeout,
        float $connectTimeout,
    ): HttpResponse {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $lines),
                'content' => $body,
                'timeout' => $timeout,
                'follow_location' => 0,

                // Sem isto, um 4xx faz file_get_contents devolver false e o
                // status se perde — trataríamos "token inválido" como falha de
                // rede e ficaríamos retentando para sempre.
                'ignore_errors' => true,
            ],
        ]);

        $previous = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) max(1, (int) ceil($connectTimeout)));

        $response = @file_get_contents($url, false, $context);

        if ($previous !== false) {
            ini_set('default_socket_timeout', $previous);
        }

        if ($response === false) {
            return HttpResponse::transportError('falha ao abrir a conexão');
        }

        // $http_response_header é populado no escopo local pelo wrapper.
        $status = $this->statusFrom($http_response_header ?? []);

        return new HttpResponse(status: $status, body: $response);
    }

    /**
     * @param list<string> $headers
     */
    private function statusFrom(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}
