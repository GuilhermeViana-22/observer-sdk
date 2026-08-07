<?php

declare(strict_types=1);

namespace Observer\Collectors;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Observer\DTO\Payloads\RequestPayload;
use Observer\Enums\EventType;
use Observer\Enums\Severity;
use Symfony\Component\HttpFoundation\Response;

/**
 * Captura a request HTTP completa.
 *
 * Usa RequestHandled, disparado pelo kernel HTTP depois que a resposta é
 * montada — é o único ponto em que método, rota, status e duração existem
 * simultaneamente. O RouteMatched só é usado para popular a tag de rota o
 * quanto antes, para que eventos gerados durante a request já a carreguem.
 */
final class RequestCollector extends AbstractCollector
{
    private ?float $startedAt = null;

    public function name(): string
    {
        return 'requests';
    }

    public function register(): void
    {
        $this->startedAt = $this->resolveStart();

        $this->listen(RouteMatched::class, function (RouteMatched $event): void {
            $observer = $this->client();

            if ($observer !== null) {
                $observer->withTags(array_filter([
                    'route' => $event->route->getName() ?? $event->route->uri(),
                ]));
            }
        });

        $this->listen(RequestHandled::class, function (RequestHandled $event): void {
            $this->capture($event->request, $event->response);
        });
    }

    public function capture(Request $request, Response $response): void
    {
        $duration = (microtime(true) - ($this->startedAt ?? microtime(true))) * 1000;
        $route = $request->route();

        $payload = new RequestPayload(
            method: $request->getMethod(),
            url: $request->fullUrl(),
            statusCode: $response->getStatusCode(),
            durationMs: $duration,
            route: is_object($route) && method_exists($route, 'uri') ? $route->uri() : null,
            action: is_object($route) && method_exists($route, 'getActionName') ? $route->getActionName() : null,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            headers: $this->boolOption('capture_headers', true) ? $this->headers($request) : [],
            query: $request->query(),
            body: $this->boolOption('capture_body', false) ? $this->body($request) : null,
            memoryPeakKb: (int) round(memory_get_peak_usage(true) / 1024),
        );

        $this->record(
            EventType::Request,
            sprintf('%s %s', $request->getMethod(), $request->getPathInfo()),
            $payload,
            $this->severityFor($response->getStatusCode(), $duration),
        );
    }

    /**
     * Erro do servidor é 'error'; erro do cliente e lentidão são 'warning'.
     */
    private function severityFor(int $status, float $durationMs): Severity
    {
        if ($status >= 500) {
            return Severity::Error;
        }

        if ($status >= 400) {
            return Severity::Warning;
        }

        $slow = $this->floatOption('slow_threshold_ms', 1000);

        return $slow > 0 && $durationMs >= $slow ? Severity::Warning : Severity::Info;
    }

    /**
     * @return array<string, mixed>
     */
    private function headers(Request $request): array
    {
        return $request->headers->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function body(Request $request): ?array
    {
        $input = $request->except(['password', 'password_confirmation']);

        return $input === [] ? null : $input;
    }

    /**
     * LARAVEL_START é definido no public/index.php e cobre também o tempo de
     * bootstrap do framework — mais fiel que marcar o tempo no boot do SDK.
     */
    private function resolveStart(): float
    {
        $constant = defined('LARAVEL_START') ? constant('LARAVEL_START') : null;

        if (is_float($constant) || is_int($constant)) {
            return (float) $constant;
        }

        $requestTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;

        return is_numeric($requestTime) ? (float) $requestTime : microtime(true);
    }
}
