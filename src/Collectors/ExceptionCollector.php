<?php

declare(strict_types=1);

namespace Observer\Collectors;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Observer\Enums\EventType;
use Observer\Enums\Severity;
use Throwable;

/**
 * Captura exceptions por três caminhos complementares:
 *
 *  1. reportable() do handler do Laravel — pega tudo que passa pelo report(),
 *     que é o caminho de 99% das exceptions em uma app Laravel.
 *  2. set_exception_handler — pega exceptions não tratadas fora do ciclo do
 *     framework (bootstrap, scripts CLI).
 *  3. register_shutdown_function — pega erros fatais (E_ERROR, E_PARSE,
 *     memória esgotada), que não geram Throwable capturável.
 *
 * O deduplicador evita registrar a mesma exception duas vezes quando ela
 * chega por mais de um caminho.
 */
final class ExceptionCollector extends AbstractCollector
{
    private ?ExceptionHandler $handler = null;

    public function name(): string
    {
        return 'exceptions';
    }

    public function withHandler(?ExceptionHandler $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    public function register(): void
    {
        $this->registerReportable();
        $this->registerGlobalHandler();
        $this->registerFatalErrorHandler();
    }

    public function capture(Throwable $exception, bool $handled = true): void
    {
        if ($this->isDuplicateException($exception)) {
            return;
        }

        $observer = $this->client();

        $payload = $observer !== null
            ? $observer->formatException($exception, $handled)->toArray()
            : ['class' => $exception::class, 'message' => $exception->getMessage()];

        $this->record(
            EventType::Exception,
            $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class,
            $payload,
            $handled ? Severity::Error : Severity::Critical,
        );
    }

    /**
     * O reportable do Laravel devolve o controle ao handler padrão quando o
     * callback não retorna false — o log da aplicação continua funcionando.
     */
    private function registerReportable(): void
    {
        if (! $this->handler instanceof Handler) {
            return;
        }

        $this->handler->reportable(function (Throwable $e): void {
            $this->logger->safely(fn () => $this->capture($e, handled: true), 'exception_collector');
        });
    }

    private function registerGlobalHandler(): void
    {
        $previous = set_exception_handler(null);

        set_exception_handler(function (Throwable $e) use ($previous): void {
            $this->logger->safely(fn () => $this->capture($e, handled: false), 'uncaught_exception');
            $this->observer()->flush();

            if ($previous !== null) {
                $previous($e);
            }
        });
    }

    private function registerFatalErrorHandler(): void
    {
        register_shutdown_function(function (): void {
            $error = error_get_last();

            if ($error === null || ! $this->isFatal($error['type'])) {
                return;
            }

            $this->logger->safely(function () use ($error): void {
                $this->record(
                    EventType::Exception,
                    $error['message'],
                    [
                        'class' => 'PHP Fatal Error',
                        'message' => $error['message'],
                        'code' => $error['type'],
                        'file' => $error['file'],
                        'line' => $error['line'],
                        'handled' => false,
                        'fingerprint' => substr(hash('xxh128', $error['file'].':'.$error['line']), 0, 16),
                    ],
                    Severity::Critical,
                );
            }, 'fatal_error');

            $this->observer()->flush();
        });
    }

    private function isFatal(int $type): bool
    {
        return in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
    }
}
