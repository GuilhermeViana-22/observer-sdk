<?php

declare(strict_types=1);

namespace Observer\Support;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registro de falhas do próprio SDK.
 *
 * Regra de ouro: uma falha do Observer nunca pode derrubar a aplicação.
 *
 * Há dois tipos de mensagem aqui, com públicos diferentes:
 *
 *   report()/debug()  → diagnóstico de quem está desenvolvendo o SDK. Só saem
 *                       com `observer.debug` ligado.
 *   warning()/error() → o SDK está configurado mas NÃO está funcionando (DSN
 *                       ignorado, servidor recusando o lote). Quem precisa
 *                       dessa linha é justamente quem não ligou o debug, então
 *                       ela sai sempre — uma vez por mensagem, por processo.
 *
 * O limite de uma vez por mensagem não é economia de disco: numa queda do
 * Observer Server, o caminho de falha roda a cada flush: sem a trava, o log da
 * aplicação observada viraria refém do nosso incidente.
 */
final class InternalLogger
{
    /**
     * Prefixo de tudo que o SDK escreve no logger da aplicação.
     *
     * Serve para o LogCollector reconhecer as próprias linhas e não transformá-las
     * em eventos faturados. Ver LogCollector::originatesInSdk().
     */
    public const PREFIX = '[observer]';

    /** @var array<string, true> */
    private array $announced = [];

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
        if (! $this->debug) {
            return;
        }

        $this->write('warning', 'falha interna do SDK', [
            'context' => $context,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile().':'.$e->getLine(),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        if (! $this->debug) {
            return;
        }

        $this->write('debug', $message, $context);
    }

    /**
     * Algo está configurado errado, ou eventos estão sendo perdidos.
     *
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->announce('warning', $message, $context);
    }

    /**
     * O envio está quebrado e não vai se recuperar sozinho.
     *
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->announce('error', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function announce(string $level, string $message, array $context): void
    {
        if (isset($this->announced[$message])) {
            return;
        }

        $this->announced[$message] = true;

        $this->write($level, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $logger = $this->logger;

        if ($logger === null) {
            return;
        }

        // Sob o guard: o que o SDK escreve no logger da aplicação não pode
        // voltar como evento pelo LogCollector. Ver SelfGuard.
        SelfGuard::run(static function () use ($logger, $level, $message, $context): void {
            try {
                $logger->log($level, self::PREFIX.' '.$message, $context);
            } catch (Throwable) {
                // Um logger quebrado é problema da aplicação, não motivo para
                // o SDK derrubar o request em que ele estava passando.
            }
        });
    }
}
