<?php

declare(strict_types=1);

namespace Observer\Support;

/**
 * Credencial do projeto em uma única string.
 *
 *   https://lsec_b2fc4cd2...@go.guilhermeviana.com/019ffe2c-5033-7cf6-...
 *           └──── chave ────┘ └────── host ──────┘ └───── projeto ─────┘
 *
 * Existe para que a aplicação monitorada precise de UMA variável de ambiente em
 * vez de três (endpoint, chave e projeto). É o mesmo formato do DSN do Sentry —
 * deliberadamente, porque quem já integrou observabilidade reconhece de imediato.
 *
 * A chave é de ESCRITA: serve para enviar eventos, nunca para lê-los. Por isso
 * pode viver no .env de uma aplicação sem expor os dados já coletados.
 */
final class Dsn
{
    public function __construct(
        public readonly string $endpoint,
        public readonly string $key,
        public readonly string $projectId,
    ) {}

    /**
     * Interpreta a string, devolvendo null quando ela não é um DSN válido.
     *
     * Nunca lança: um DSN digitado errado precisa desligar o envio e registrar
     * o problema, não derrubar o boot da aplicação do cliente.
     */
    public static function parse(?string $dsn): ?self
    {
        $dsn = trim((string) $dsn);

        if ($dsn === '') {
            return null;
        }

        $parts = parse_url($dsn);

        if ($parts === false) {
            return null;
        }

        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        $key = $parts['user'] ?? '';
        $path = trim($parts['path'] ?? '', '/');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || $key === '' || $path === '') {
            return null;
        }

        // O identificador do projeto é o ÚLTIMO segmento: assim o DSN continua
        // válido se a API viver sob um prefixo de caminho.
        $segments = explode('/', $path);
        $projectId = array_pop($segments);

        if ($projectId === null || $projectId === '') {
            return null;
        }

        $endpoint = $scheme.'://'.$host;

        if (isset($parts['port'])) {
            $endpoint .= ':'.$parts['port'];
        }

        if ($segments !== []) {
            $endpoint .= '/'.implode('/', $segments);
        }

        return new self(endpoint: $endpoint, key: $key, projectId: $projectId);
    }
}
