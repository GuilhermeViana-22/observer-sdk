<?php

declare(strict_types=1);

namespace Observer\Pipeline\Processors;

use Observer\Contracts\EventProcessor;
use Observer\DTO\Event;
use Observer\Services\Redactor;

/**
 * Mascara dados sensíveis, remove PII e aplica os limites de tamanho.
 *
 * Roda depois do enriquecimento de contexto — de propósito: o contexto também
 * precisa ser mascarado (e-mail do usuário, headers, cookies).
 */
final class ScrubbingProcessor implements EventProcessor
{
    /**
     * Campos considerados identificáveis. Removidos quando send_default_pii
     * está desligado (padrão), para conformidade com LGPD/GDPR.
     */
    private const PII_PAYLOAD_KEYS = ['ip', 'user_agent', 'body'];

    private const PII_USER_KEYS = ['email', 'name', 'username', 'ip'];

    public function __construct(
        private readonly Redactor $redactor,
        private readonly bool $sendDefaultPii = false,
    ) {}

    public function process(Event $event): Event
    {
        $payload = $event->payload;
        $context = $event->context;

        if (! $this->sendDefaultPii) {
            $payload = $this->stripPii($payload);
            $context = $this->stripUserPii($context);
        }

        // Headers têm regra própria (nomes normalizados, lista específica).
        if (isset($payload['headers']) && is_array($payload['headers'])) {
            $headers = $this->redactor->scrubHeaders($payload['headers']);
            unset($payload['headers']);
            $payload = $this->redactor->scrub($payload);
            $payload['headers'] = $headers;
        } else {
            $payload = $this->redactor->scrub($payload);
        }

        return $event
            ->withPayload($payload)
            ->withContext($this->redactor->scrub($context));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function stripPii(array $payload): array
    {
        foreach (self::PII_PAYLOAD_KEYS as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    /**
     * O ID do usuário é preservado: sem ele não há como correlacionar erros
     * por conta, e um ID interno não identifica a pessoa fora do sistema.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function stripUserPii(array $context): array
    {
        if (! isset($context['user']) || ! is_array($context['user'])) {
            return $context;
        }

        foreach (self::PII_USER_KEYS as $key) {
            unset($context['user'][$key]);
        }

        return $context;
    }
}
