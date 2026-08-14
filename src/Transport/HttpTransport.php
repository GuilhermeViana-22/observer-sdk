<?php

declare(strict_types=1);

namespace Observer\Transport;

use Observer\Contracts\HttpSender;
use Observer\Contracts\Serializer;
use Observer\Contracts\Transport;
use Observer\DTO\Event;
use Observer\Support\InternalLogger;
use Observer\Transport\Http\SenderFactory;

/**
 * Transporte HTTP para o Observer Server (API REST em Go).
 *
 * Batch-first, autenticação por bearer token, gzip opcional e retry com
 * backoff exponencial + jitter.
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
        ?HttpSender $sender = null,

        /**
         * Teto de tempo para TODAS as tentativas somadas.
         *
         * Sem ele, `retries: 2` com `timeout: 2.0` e delays de 200/400 ms
         * chega a ~6,6 s no pior caso — dentro do terminate() do Laravel, isso
         * é tempo somado à página do usuário final do cliente.
         */
        private readonly int $totalBudgetMs = 3000,

        /** Teto de eventos acumulados antes de um envio forçado. */
        private readonly int $maxBatch = 50,
    ) {
        $this->sender = $sender ?? SenderFactory::detect();
    }

    private readonly HttpSender $sender;

    /**
     * Desliga o envio pelo resto do processo após um 401.
     *
     * Uma chave errada não melhora com repetição: sem esta trava, cada flush
     * refaria a mesma requisição condenada até o fim do processo.
     */
    private bool $disabled = false;

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

        // Acumular até o teto preserva a coalescência quando o EventBuffer está
        // em modo passthrough (buffer.size = 1), e o teto impede que um worker
        // de fila longo cresça em memória sem limite.
        if (count($this->pending) >= $this->maxBatch) {
            $this->dispatchPending();
        }
    }

    public function flush(): bool
    {
        if ($this->pending === []) {
            return true;
        }

        return $this->dispatchPending();
    }

    /**
     * Envia tudo que está pendente, em blocos do tamanho do lote.
     */
    private function dispatchPending(): bool
    {
        $chunks = array_chunk($this->pending, max(1, $this->maxBatch));

        // Limpa ANTES de enviar. Se um envio lançasse com o buffer ainda cheio,
        // a próxima chamada reenviaria os mesmos eventos — e o retry já é
        // responsabilidade do dispatch().
        $this->pending = [];

        $ok = true;
        foreach ($chunks as $chunk) {
            $ok = $this->dispatch($chunk) && $ok;
        }

        return $ok;
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
     * Envia um lote, com retry conforme o contrato do servidor.
     *
     * NUNCA lança. O EventBuffer::flush() tem try/catch, mas o
     * EventBuffer::add() NÃO tem — e no modo passthrough uma exceção daqui
     * subiria pelo Log::info() da aplicação do cliente. O invariante do SDK é
     * que uma falha nossa jamais derruba a aplicação observada.
     *
     * @param list<Event> $events
     */
    public function dispatch(array $events): bool
    {
        if ($events === [] || $this->disabled) {
            return false;
        }

        try {
            return $this->attempt($events);
        } catch (\Throwable $e) {
            $this->logger?->error('HttpTransport falhou de forma inesperada.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param list<Event> $events
     */
    private function attempt(array $events): bool
    {
        ['body' => $body, 'headers' => $headers] = $this->buildRequest($events);

        $url = $this->url();
        $startedAt = microtime(true);
        $attempts = $this->maxAttempts();

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = $this->sender->send(
                $url,
                $body,
                $headers,
                $this->timeout,
                $this->connectTimeout,
            );

            if ($response->accepted()) {
                return true;
            }

            if ($response->permanentFailure()) {
                // 401 desliga o transporte pelo resto do processo: a chave não
                // vai ficar correta entre um flush e outro.
                if ($response->status === 401) {
                    $this->disabled = true;
                }

                $this->logger?->error('Observer Server recusou o lote; eventos descartados.', [
                    'status' => $response->status,
                    'events' => count($events),
                ]);

                return false;
            }

            $isLastAttempt = $attempt === $attempts;

            if ($isLastAttempt || $this->budgetExhausted($startedAt)) {
                break;
            }

            // O servidor manda Retry-After em caso de sobrecarga, mas dormir
            // esse tempo aqui somaria segundos à página do usuário final do
            // cliente — estamos dentro do ciclo de request dele. O backoff
            // curto próprio é a única espera aceitável.
            usleep($this->retryDelayFor($attempt) * 1000);
        }

        $this->logger?->warning('Observer Server indisponível; lote perdido.', [
            'events' => count($events),
        ]);

        return false;
    }

    private function budgetExhausted(float $startedAt): bool
    {
        return (microtime(true) - $startedAt) * 1000 >= $this->totalBudgetMs;
    }
}
