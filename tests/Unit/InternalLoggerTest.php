<?php

declare(strict_types=1);

namespace Observer\Tests\Unit;

use Observer\Support\InternalLogger;
use Observer\Support\SelfGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

final class InternalLoggerTest extends TestCase
{
    protected function tearDown(): void
    {
        SelfGuard::reset();

        parent::tearDown();
    }

    /**
     * A regressão que derrubava o artisan inteiro: TransportManager e
     * HttpTransport chamam warning()/error(), que não existiam na classe.
     */
    public function test_expoe_os_niveis_usados_pelo_transporte(): void
    {
        $this->assertTrue(method_exists(InternalLogger::class, 'warning'));
        $this->assertTrue(method_exists(InternalLogger::class, 'error'));
    }

    public function test_warning_e_error_saem_mesmo_com_debug_desligado(): void
    {
        $spy = $this->spy();
        $logger = new InternalLogger($spy, debug: false);

        $logger->warning('transporte ignorado', ['driver' => 'file']);
        $logger->error('dsn inválido');

        $this->assertCount(2, $spy->records);
        $this->assertSame('warning', $spy->records[0]['level']);
        $this->assertSame(InternalLogger::PREFIX.' transporte ignorado', $spy->records[0]['message']);
        $this->assertSame(['driver' => 'file'], $spy->records[0]['context']);
        $this->assertSame('error', $spy->records[1]['level']);
    }

    public function test_repete_no_maximo_uma_vez_por_mensagem(): void
    {
        $spy = $this->spy();
        $logger = new InternalLogger($spy, debug: false);

        for ($i = 0; $i < 10; $i++) {
            $logger->warning('servidor indisponível');
        }

        $logger->warning('outro motivo');

        $this->assertCount(2, $spy->records);
    }

    public function test_diagnostico_continua_preso_ao_debug(): void
    {
        $spy = $this->spy();

        (new InternalLogger($spy, debug: false))->debug('detalhe');
        (new InternalLogger($spy, debug: false))->report(new RuntimeException('x'));

        $this->assertSame([], $spy->records);

        $verbose = new InternalLogger($spy, debug: true);
        $verbose->debug('detalhe');
        $verbose->report(new RuntimeException('x'));

        $this->assertCount(2, $spy->records);
    }

    public function test_escreve_sob_o_guard_para_nao_virar_evento(): void
    {
        $spy = new class extends AbstractLogger
        {
            public bool $guardAtivo = false;

            /** @param array<string, mixed> $context */
            public function log($level, $message, array $context = []): void
            {
                $this->guardAtivo = SelfGuard::active();
            }
        };

        (new InternalLogger($spy))->error('qualquer coisa');

        $this->assertTrue($spy->guardAtivo);
        $this->assertFalse(SelfGuard::active(), 'o guard precisa ser liberado depois da escrita');
    }

    public function test_sem_logger_nao_lanca(): void
    {
        $logger = new InternalLogger(null, debug: true);

        $logger->warning('a');
        $logger->error('b');
        $logger->debug('c');
        $logger->report(new RuntimeException('d'));

        $this->assertTrue(true);
    }

    public function test_logger_quebrado_nao_derruba_a_aplicacao(): void
    {
        $explosivo = new class extends AbstractLogger
        {
            /** @param array<string, mixed> $context */
            public function log($level, $message, array $context = []): void
            {
                throw new RuntimeException('canal de log quebrado');
            }
        };

        (new InternalLogger($explosivo))->error('qualquer coisa');

        $this->assertFalse(SelfGuard::active());
    }

    private function spy(): object
    {
        return new class extends AbstractLogger
        {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }
}
