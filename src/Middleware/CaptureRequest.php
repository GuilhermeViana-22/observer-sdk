<?php

declare(strict_types=1);

namespace Observer\Middleware;

use Closure;
use Illuminate\Http\Request;
use Observer\Contracts\ClientInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garante a entrega dos eventos no fim da request.
 *
 * O flush acontece em terminate(), depois que a resposta já foi enviada ao
 * cliente — é o "fire and forget" na prática: o custo do I/O do SDK não entra
 * no tempo de resposta percebido pelo usuário.
 *
 * O middleware é empilhado automaticamente pelo ServiceProvider; não é preciso
 * registrá-lo no Kernel/bootstrap da aplicação.
 */
final class CaptureRequest
{
    public function __construct(private readonly ClientInterface $observer) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->observer->flush();
    }
}
