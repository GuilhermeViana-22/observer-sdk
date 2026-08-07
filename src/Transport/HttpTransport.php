<?php

declare(strict_types=1);

namespace Observer\Transport;

use Observer\Contracts\Serializer;
use Observer\Contracts\Transport;
use Observer\DTO\Event;
use Observer\Exceptions\TransportException;
use Observer\Support\InternalLogger;

/**
 * Transporte HTTP para o Observer Server (API REST em Go).
 *
 * FASE FUTURA — a implementação de envio está deliberadamente ausente porque
 * o servidor ainda não existe. A classe já está aqui para fixar o contrato:
 * batch-first, autenticação por bearer token, gzip opcional e retry com
 * backoff exponencial + jitter.
 *
 * Quando o servidor existir, apenas dispatch() precisa ser implementado —
 * nenhum collector, pipeline ou API pública muda.
 *
 * Contrato acordado com o servidor:
 *   POST {endpoint}/api/v1/events
 *   Authorization: Bearer {api_key}
 *   Content-Type: application/json
 *   Content-Encoding: gzip (opcional)
 *   Body: {"events": [...], "count": N}
 *   200/202 = aceito · 4xx = descartar (não readequar) · 5xx = retry
 */
final class HttpTransport implements Transport
{
    /** @var list<Event> */
    private array $pending = [];

    public function __construct(
        private readonly string $endpoint,
        private readonly ?string $apiKey,
        private readonly Serializer $serializer,
        private readonly float $timeout = 2.0,
        private readonly float $connectTimeout = 1.0,
        private readonly int $retries = 2,
        private readonly int $retryDelayMs = 200,
        private readonly bool $compress = true,
        private readonly int $compressThreshold = 1024,
        private readonly ?InternalLogger $logger = null,
    ) {}

    public function send(Event $event): void
    {
        $this->sendBatch([$event]);
    }

    public function sendBatch(array $events): void
    {
        if ($events === []) {
            return;
        }

        $this->pending = array_merge($this->pending, $events);

        $this->logger?->debug('HttpTransport ainda não implementado; eventos mantidos em memória.', [
            'pending' => count($this->pending),
            'endpoint' => $this->endpoint,
        ]);
    }

    public function flush(): bool
    {
        if ($this->pending === []) {
            return true;
        }

        $this->pending = [];

        return false;
    }

    public function close(): void
    {
        $this->flush();
    }

    /**
     * Corpo da requisição, já serializado e opcionalmente comprimido.
     *
     * @param list<Event> $events
     * @return array{body: string, headers: array<string, string>}
     */
    public function buildRequest(array $events): array
    {
        $body = $this->serializer->serializeBatch($events);

        $headers = [
            'Content-Type' => $this->serializer->contentType(),
            'Accept' => 'application/json',
            'User-Agent' => 'observer-php-sdk/1.0',
        ];

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['Authorization'] = "Bearer {$this->apiKey}";
        }

        if ($this->compress && strlen($body) >= $this->compressThreshold && function_exists('gzencode')) {
            $compressed = gzencode($body, 6);

            if ($compressed !== false) {
                $body = $compressed;
                $headers['Content-Encoding'] = 'gzip';
            }
        }

        return ['body' => $body, 'headers' => $headers];
    }

    public function url(): string
    {
        return rtrim($this->endpoint, '/').'/api/v1/events';
    }

    /**
     * Backoff exponencial com jitter, para não sincronizar retentativas de
     * várias instâncias após uma queda do servidor.
     */
    public function retryDelayFor(int $attempt): int
    {
        $base = $this->retryDelayMs * (2 ** max(0, $attempt - 1));

        return (int) ($base + random_int(0, max(1, (int) ($base * 0.25))));
    }

    public function maxAttempts(): int
    {
        return $this->retries + 1;
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    public function connectTimeout(): float
    {
        return $this->connectTimeout;
    }

    /**
     * Único ponto a implementar quando o Observer Server entrar no ar.
     *
     * @param list<Event> $events
     *
     * @throws TransportException
     */
    public function dispatch(array $events): never
    {
        throw TransportException::notImplemented('http');
    }
}
