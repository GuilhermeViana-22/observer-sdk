<?php

declare(strict_types=1);

namespace Observer\Collectors;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Observer\DTO\Payloads\HttpClientPayload;
use Observer\Enums\EventType;
use Observer\Enums\Severity;

/**
 * Captura chamadas HTTP de saída.
 *
 * Cobre tanto o Laravel HTTP Client quanto o Guzzle usado por baixo dele:
 * os eventos RequestSending/ResponseReceived são emitidos pelo PendingRequest,
 * que é o wrapper do Guzzle.
 *
 * A duração é medida entre RequestSending e ResponseReceived, indexada pela
 * identidade do objeto de request — o Laravel não fornece o tempo pronto.
 */
final class HttpClientCollector extends AbstractCollector
{
    /** @var array<string, float> */
    private array $timers = [];

    public function name(): string
    {
        return 'http_client';
    }

    public function register(): void
    {
        $this->listen(RequestSending::class, function (RequestSending $event): void {
            $this->timers[spl_object_hash($event->request)] = microtime(true);

            // Guarda de memória para processos longevos (workers, Octane).
            if (count($this->timers) > 200) {
                $this->timers = [];
            }
        });

        $this->listen(ResponseReceived::class, function (ResponseReceived $event): void {
            $url = $event->request->url();
            $status = $event->response->status();

            $this->push(new HttpClientPayload(
                method: $event->request->method(),
                url: $url,
                statusCode: $status,
                durationMs: $this->elapsed($event->request),
                host: $this->host($url),
                responseSize: strlen($event->response->body()),
                failed: $status >= 400,
                headers: $this->boolOption('capture_headers', false) ? $event->request->headers() : [],
            ), $status >= 500 ? Severity::Error : ($status >= 400 ? Severity::Warning : Severity::Info));
        });

        $this->listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            $url = $event->request->url();

            $this->push(new HttpClientPayload(
                method: $event->request->method(),
                url: $url,
                durationMs: $this->elapsed($event->request),
                host: $this->host($url),
                failed: true,
                error: 'connection_failed',
            ), Severity::Error);
        });
    }

    private function push(HttpClientPayload $payload, Severity $level): void
    {
        $this->record(
            EventType::HttpClient,
            sprintf('%s %s', $payload->method, $payload->url),
            $payload,
            $level,
        );
    }

    private function elapsed(object $request): ?float
    {
        $hash = spl_object_hash($request);
        $startedAt = $this->timers[$hash] ?? null;

        unset($this->timers[$hash]);

        return $startedAt === null ? null : (microtime(true) - $startedAt) * 1000;
    }

    private function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }
}
