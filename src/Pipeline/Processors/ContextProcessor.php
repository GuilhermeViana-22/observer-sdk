<?php

declare(strict_types=1);

namespace Observer\Pipeline\Processors;

use Observer\Contracts\EventProcessor;
use Observer\DTO\Event;
use Observer\Enums\EventType;
use Observer\Observer;
use Observer\Services\ScopeManager;
use Observer\Support\Configuration;

/**
 * Enriquece o evento com o contexto transversal.
 *
 * Os collectors produzem apenas o que é específico do seu domínio; tudo o que
 * é comum (identidade da app, runtime, servidor, usuário, trace, breadcrumbs)
 * é anexado aqui, em um único lugar.
 */
final class ContextProcessor implements EventProcessor
{
    /** @var array<string, mixed>|null */
    private ?array $runtimeCache = null;

    public function __construct(
        private readonly Configuration $config,
        private readonly ScopeManager $scope,
    ) {}

    public function process(Event $event): Event
    {
        $context = [
            'project' => $this->config->string('project'),
            'environment' => $this->config->environment(),
            'release' => $this->config->string('release'),
            'trace_id' => $this->scope->traceId(),
        ];

        if ($this->config->bool('context.runtime', true)) {
            $context['runtime'] = $this->runtime();
        }

        if ($this->config->bool('context.server', true)) {
            $context['server'] = [
                'name' => $this->config->string('server_name'),
                'memory_usage_kb' => (int) round(memory_get_usage(true) / 1024),
            ];
        }

        if ($this->config->bool('context.user', true) && $this->scope->user() !== []) {
            $context['user'] = $this->scope->user();
        }

        if ($this->scope->extra() !== []) {
            $context['extra'] = $this->scope->extra();
        }

        // Breadcrumbs só interessam quando algo deu errado — anexá-las em
        // toda query multiplicaria o tamanho do payload sem ganho.
        if ($event->type === EventType::Exception) {
            $breadcrumbs = $this->scope->breadcrumbs();

            if ($breadcrumbs !== []) {
                $context['breadcrumbs'] = $breadcrumbs;
            }
        }

        /** @var array<string, scalar> $defaultTags */
        $defaultTags = $this->config->array('context.tags');

        return $event
            ->mergeContext(array_filter($context, static fn (mixed $v): bool => $v !== null))
            ->withTags(array_merge($defaultTags, $this->scope->tags()));
    }

    /**
     * @return array<string, mixed>
     */
    private function runtime(): array
    {
        return $this->runtimeCache ??= [
            'name' => 'php',
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'sdk' => 'observer-php-sdk',
            'sdk_version' => Observer::VERSION,
        ];
    }
}
