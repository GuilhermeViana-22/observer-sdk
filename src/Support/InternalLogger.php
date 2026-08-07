<?php

declare(strict_types=1);

namespace Observer\Support;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registro de falhas do próprio SDK.
 *
 * Regra de ouro: uma falha do Observer nunca pode derrubar a aplicação.
 * Com `observer.debug` desligado, o erro é silenciado; ligado, vai para o
 * logger da aplicação.
 */
final class InternalLogger
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $debug = false,
    ) {}

    /**
     * Executa o callback isolando qualquer falha.
     *
     * @template T
     *
     * @param callable(): T $callback
     * @return T|null
     */
    public function safely(callable $callback, string $context = 'observer'): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            $this->report($e, $context);

            return null;
        }
    }

    public function report(Throwable $e, string $context = 'observer'): void
    {
        if (! $this->debug || $this->logger === null) {
            return;
        }

        $this->logger->warning('[observer] falha interna do SDK', [
            'context' => $context,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile().':'.$e->getLine(),
        ]);
    }

    public function debug(string $message, array $context = []): void
    {
        if (! $this->debug || $this->logger === null) {
            return;
        }

        $this->logger->debug("[observer] {$message}", $context);
    }
}
