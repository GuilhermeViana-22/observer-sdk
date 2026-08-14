<?php

declare(strict_types=1);

namespace Observer\Tests\Unit;

use Observer\Contracts\HttpSender;
use Observer\DTO\Event;
use Observer\Enums\EventType;
use Observer\Enums\Severity;
use Observer\Serializers\JsonSerializer;
use Observer\Support\SelfGuard;
use Observer\Transport\Http\HttpResponse;
use Observer\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * O SDK é o MEIO, nunca o fim.
 *
 * Nada que aconteça dentro do Observer pode virar um evento do Observer. Este
 * arquivo cobre a garantia e, principalmente, o laço que ela existe para
 * fechar — um laço que rodou em produção:
 *
 *   envio → curl_close() emite deprecated do PHP 8.5 → Laravel loga como
 *   warning → LogCollector captura → vira evento → próximo envio ⟲
 *
 * Cada envio produzia ao menos um evento novo. O volume nunca chegava a zero e
 * era cobrado ao cliente em ingestão e retenção.
 */

/**
 * Sender que emite um log durante o envio, como o PHP faz com um deprecated.
 */
final class NoisySender implements HttpSender
{
    public int $sends = 0;

    /** @param callable(): void $onSend o que o "PHP" emite durante o envio */
    public function __construct(private $onSend) {}

    public function send(string $url, string $body, array $headers, float $timeout, float $connectTimeout): HttpResponse
    {
        $this->sends++;

        ($this->onSend)();

        return new HttpResponse(202);
    }
}

final class SelfGuardTest extends TestCase
{
    protected function setUp(): void
    {
        SelfGuard::reset();
    }

    protected function tearDown(): void
    {
        SelfGuard::reset();
    }

    public function test_fora_do_sdk_o_guard_esta_inativo(): void
    {
        $this->assertFalse(SelfGuard::active());
    }

    public function test_marca_e_libera(): void
    {
        $dentro = SelfGuard::run(static fn (): bool => SelfGuard::active());

        $this->assertTrue($dentro);
        $this->assertFalse(SelfGuard::active(), 'o guard ficou preso depois do bloco');
    }

    /**
     * O envio pode acontecer de dentro de um registro (buffer com size=1
     * despacha na hora). Com um booleano, o bloco interno liberaria o guard
     * enquanto o externo ainda está rodando — e o laço voltaria a existir.
     */
    public function test_e_reentrante(): void
    {
        SelfGuard::run(function (): void {
            SelfGuard::run(static fn () => null);

            $this->assertTrue(
                SelfGuard::active(),
                'o bloco interno liberou o guard com o externo ainda ativo',
            );
        });

        $this->assertFalse(SelfGuard::active());
    }

    public function test_libera_mesmo_com_exception(): void
    {
        try {
            SelfGuard::run(static function (): void {
                throw new \RuntimeException('falha no envio');
            });
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertFalse(SelfGuard::active(), 'exception deixou o guard preso');
    }

    // -----------------------------------------------------------------------
    // O laço propriamente dito
    // -----------------------------------------------------------------------

    /**
     * O teste que dá nome ao arquivo: enquanto o transporte envia, o guard
     * está ativo — e é isso que faz o collector descartar o que o PHP emitir
     * dali de dentro.
     */
    public function test_o_guard_esta_ativo_durante_o_envio(): void
    {
        $ativoDuranteOEnvio = false;

        $sender = new NoisySender(function () use (&$ativoDuranteOEnvio): void {
            // Onde o curl_close() emitia o deprecated.
            $ativoDuranteOEnvio = SelfGuard::active();
        });

        $transport = $this->transport($sender);
        $transport->sendBatch([$this->event()]);
        $transport->flush();

        $this->assertTrue(
            $ativoDuranteOEnvio,
            'o envio rodou fora do guard — um deprecated aqui viraria evento e o laço voltaria',
        );
    }

    public function test_o_guard_e_liberado_depois_do_envio(): void
    {
        $transport = $this->transport(new NoisySender(static fn () => null));

        $transport->sendBatch([$this->event()]);
        $transport->flush();

        $this->assertFalse(SelfGuard::active(), 'o guard ficou preso depois do envio');
    }

    /**
     * O envio não pode engolir a captura da APLICAÇÃO depois de terminar: o
     * guard fecha o SDK para si mesmo, não silencia o cliente.
     */
    public function test_o_guard_nao_vaza_para_o_evento_seguinte(): void
    {
        $sender = new NoisySender(static fn () => null);
        $transport = $this->transport($sender);

        $transport->sendBatch([$this->event('primeiro')]);
        $transport->flush();
        $transport->sendBatch([$this->event('segundo')]);
        $transport->flush();

        $this->assertSame(2, $sender->sends, 'o segundo evento não foi enviado');
    }

    // -----------------------------------------------------------------------

    private function event(string $message = 'oi'): Event
    {
        return Event::make(EventType::Log, $message, ['message' => $message], Severity::Info);
    }

    /** Buffer de 1: cada record despacha na hora, que é o caminho reentrante. */
    private function transport(HttpSender $sender): HttpTransport
    {
        return new HttpTransport(
            endpoint: 'https://observer.test',
            apiKey: 'lsec_1234567890abcdef',
            serializer: new JsonSerializer,
            timeout: 2.0,
            connectTimeout: 1.0,
            retries: 0,
            retryDelayMs: 1,
            compress: false,
            compressThreshold: 1024,
            logger: null,
            sender: $sender,
            totalBudgetMs: 3000,
            maxBatch: 1,
        );
    }
}
