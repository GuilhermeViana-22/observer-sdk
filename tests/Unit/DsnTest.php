<?php

declare(strict_types=1);

namespace Observer\Tests\Unit;

use Observer\Support\Dsn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DsnTest extends TestCase
{
    public function test_interpreta_um_dsn_real(): void
    {
        $dsn = Dsn::parse('https://lsec_b2fc4cd244033c4d14bc78a8169662f47f5640e1@go.guilhermeviana.com/019ffe2c-5033-7cf6-a89c-34d7e52baf5b');

        $this->assertNotNull($dsn);
        $this->assertSame('https://go.guilhermeviana.com', $dsn->endpoint);
        $this->assertSame('lsec_b2fc4cd244033c4d14bc78a8169662f47f5640e1', $dsn->key);
        $this->assertSame('019ffe2c-5033-7cf6-a89c-34d7e52baf5b', $dsn->projectId);
    }

    public function test_preserva_porta_em_desenvolvimento(): void
    {
        $dsn = Dsn::parse('http://lsec_abc@127.0.0.1:8030/proj-1');

        $this->assertNotNull($dsn);
        $this->assertSame('http://127.0.0.1:8030', $dsn->endpoint);
        $this->assertSame('proj-1', $dsn->projectId);
    }

    /**
     * O identificador do projeto é o último segmento, então a API pode viver
     * sob um prefixo de caminho sem quebrar o formato.
     */
    public function test_aceita_prefixo_de_caminho(): void
    {
        $dsn = Dsn::parse('https://lsec_abc@exemplo.com/observer/api/proj-9');

        $this->assertNotNull($dsn);
        $this->assertSame('https://exemplo.com/observer/api', $dsn->endpoint);
        $this->assertSame('proj-9', $dsn->projectId);
    }

    #[DataProvider('dsnsInvalidos')]
    public function test_recusa_dsn_invalido(?string $value): void
    {
        $this->assertNull(Dsn::parse($value));
    }

    /** @return array<string, array{0: string|null}> */
    public static function dsnsInvalidos(): array
    {
        return [
            'nulo' => [null],
            'vazio' => [''],
            'só espaços' => ['   '],
            'sem chave' => ['https://go.guilhermeviana.com/proj-1'],
            'sem projeto' => ['https://lsec_abc@go.guilhermeviana.com'],
            'sem host' => ['https://lsec_abc@/proj-1'],
            'esquema inesperado' => ['ftp://lsec_abc@exemplo.com/proj-1'],
            'texto solto' => ['lsec_abc'],
        ];
    }

    /**
     * Um DSN digitado errado precisa desligar o envio, não derrubar o boot da
     * aplicação que está sendo monitorada.
     */
    public function test_nunca_lanca(): void
    {
        $this->assertNull(Dsn::parse('://%%%inválido'));
    }
}
