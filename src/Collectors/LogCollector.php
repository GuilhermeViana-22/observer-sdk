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

            // Segunda camada do "o SDK é o meio, não o fim".
            //
            // O SelfGuard cobre o que o SDK emite enquanto registra ou envia,
            // que é o laço de verdade. Este filtro pega o resíduo: um
            // deprecated ou warning disparado de dentro do pacote em qualquer
            // outro momento — no boot do provider, num destrutor, num shutdown
            // handler — onde o guard já foi liberado.
            //
            // Erro do SDK não é observação da aplicação. Quando algo aqui
            // dentro falha, quem registra é o InternalLogger, no canal da
            // aplicação, sem virar evento faturado.
            if (self::originatesInSdk($log->message)) {
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
     * O texto veio de dentro do próprio pacote?
     *
     * Os avisos do motor do PHP — deprecated, warning, notice — chegam ao
     * logger com o arquivo e a linha embutidos na mensagem:
     *
     *   "Function curl_close() is deprecated since 8.5 [...]
     *    in /var/www/html/vendor/guilhermeviana-observer/sdk/src/Transport/
     *    Http/CurlSender.php on line 66"
     *
     * Comparar contra o diretório real do pacote (resolvido de __DIR__, não
     * escrito à mão) é o que sobrevive a instalação por vendor, por path
     * repository ou por symlink em desenvolvimento.
     *
     * Casar por substring é grosseiro, e é de propósito: a alternativa seria
     * um debug_backtrace() por linha de log da aplicação inteira — caro num
     * caminho que roda a cada Log::info(). O falso positivo possível é um log
     * da aplicação que cite o caminho do SDK, o que só acontece quando ela já
     * está falando de um problema do SDK.
     */
    private static function originatesInSdk(string $message): bool
    {
        static $root = null;

        // dirname duas vezes: daqui (src/Collectors) até a raiz de src/.
        $root ??= dirname(__DIR__);

        return str_contains($message, $root);
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
