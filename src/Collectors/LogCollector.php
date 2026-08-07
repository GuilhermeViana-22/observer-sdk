<?php

declare(strict_types=1);

namespace Observer\Collectors;

use Illuminate\Log\Events\MessageLogged;
use Observer\DTO\Event;
use Observer\DTO\Payloads\LogPayload;
use Observer\Enums\EventType;
use Observer\Enums\Severity;
use Throwable;

/**
 * Captura tudo o que passa pelo logger da aplicação.
 *
 * Usa MessageLogged (disparado pelo Illuminate\Log\Logger em todos os níveis
 * PSR-3) em vez de um handler Monolog acoplado a um canal específico: assim
 * funciona com qualquer canal configurado, sem exigir alteração no
 * config/logging.php do desenvolvedor.
 *
 * Para quem preferir o caminho Monolog, o pacote também oferece o
 * Observer\Log\ObserverHandler.
 */
final class LogCollector extends AbstractCollector
{
    public function name(): string
    {
        return 'logs';
    }

    public function register(): void
    {
        $this->listen(MessageLogged::class, function (MessageLogged $log): void {
            $severity = Severity::fromPsrLevel($log->level);

            if (! $severity->isAtLeast($this->config()->minimumLogLevel())) {
                return;
            }

            $context = $log->context;

            // Uma exception logada via Log::error('...', ['exception' => $e])
            // vira um evento de exception, muito mais rico que um log de texto.
            $exception = $context['exception'] ?? null;

            if ($exception instanceof Throwable) {
                unset($context['exception']);

                // Já capturada pelo ExceptionCollector: não duplica.
                if ($this->isDuplicateException($exception)) {
                    return;
                }

                $this->emit(
                    Event::make(
                        EventType::Exception,
                        $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class,
                        $this->formatException($exception),
                        $severity,
                    )
                );

                return;
            }

            $this->record(
                EventType::Log,
                $log->message,
                new LogPayload(
                    message: $log->message,
                    level: $severity->value,
                    channel: $this->channel(),
                    context: $context,
                ),
                $severity,
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function formatException(Throwable $exception): array
    {
        $observer = $this->client();

        if ($observer !== null) {
            return $observer->formatException($exception)->toArray();
        }

        return [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
    }

    private function channel(): ?string
    {
        $channels = $this->arrayOption('channels');

        return $channels === [] ? null : ($channels[0] ?? null);
    }
}
