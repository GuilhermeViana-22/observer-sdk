<?php

declare(strict_types=1);

namespace Observer\Services;

use Observer\DTO\Breadcrumb;
use Observer\Enums\Severity;
use Observer\Support\Uuid;

/**
 * Escopo corrente da execução.
 *
 * Guarda o que é transversal a todos os eventos de uma mesma request/job:
 * usuário autenticado, tags, dados extras, breadcrumbs e o trace id que
 * correlaciona os eventos entre si.
 */
final class ScopeManager
{
    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, scalar> */
    private array $tags = [];

    /** @var array<string, mixed> */
    private array $extra = [];

    /** @var list<Breadcrumb> */
    private array $breadcrumbs = [];

    private ?string $traceId = null;

    public function __construct(private readonly int $maxBreadcrumbs = 50) {}

    /**
     * @param array<string, mixed> $user
     */
    public function setUser(array $user): void
    {
        $this->user = $user;
    }

    /**
     * @return array<string, mixed>
     */
    public function user(): array
    {
        return $this->user;
    }

    /**
     * @param array<string, scalar> $tags
     */
    public function addTags(array $tags): void
    {
        $this->tags = array_merge($this->tags, $tags);
    }

    /**
     * @return array<string, scalar>
     */
    public function tags(): array
    {
        return $this->tags;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function addExtra(array $extra): void
    {
        $this->extra = array_merge($this->extra, $extra);
    }

    /**
     * @return array<string, mixed>
     */
    public function extra(): array
    {
        return $this->extra;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function addBreadcrumb(string $category, string $message, array $data = [], Severity $level = Severity::Info): void
    {
        if ($this->maxBreadcrumbs <= 0) {
            return;
        }

        $this->breadcrumbs[] = new Breadcrumb($category, $message, $level, $data);

        // Janela deslizante: mantém apenas as N mais recentes.
        if (count($this->breadcrumbs) > $this->maxBreadcrumbs) {
            $this->breadcrumbs = array_slice($this->breadcrumbs, -$this->maxBreadcrumbs);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function breadcrumbs(): array
    {
        return array_map(static fn (Breadcrumb $b): array => $b->toArray(), $this->breadcrumbs);
    }

    /**
     * ID de correlação da execução corrente. Gerado sob demanda.
     */
    public function traceId(): string
    {
        return $this->traceId ??= Uuid::v4();
    }

    public function setTraceId(string $traceId): void
    {
        $this->traceId = $traceId;
    }

    /**
     * Limpa o escopo entre execuções — essencial em workers de fila e Octane,
     * onde o processo é reaproveitado entre jobs/requests.
     */
    public function clear(): void
    {
        $this->user = [];
        $this->tags = [];
        $this->extra = [];
        $this->breadcrumbs = [];
        $this->traceId = null;
    }
}
