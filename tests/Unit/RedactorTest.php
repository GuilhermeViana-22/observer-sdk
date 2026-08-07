<?php

declare(strict_types=1);

namespace Observer\Tests\Unit;

use Observer\Services\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    private function redactor(int $maxString = 4096, int $maxItems = 100, int $maxDepth = 8): Redactor
    {
        return new Redactor(
            keys: ['password', 'token', 'api_key', 'secret*'],
            headers: ['authorization', 'cookie'],
            maxStringLength: $maxString,
            maxArrayItems: $maxItems,
            maxDepth: $maxDepth,
        );
    }

    public function test_mascara_chaves_sensiveis_em_qualquer_profundidade(): void
    {
        $result = $this->redactor()->scrub([
            'user' => ['email' => 'a@b.com', 'password' => 'segredo'],
            'API_KEY' => 'abc123',
            'nested' => ['deep' => ['token' => 'xyz']],
        ]);

        $this->assertSame('[FILTERED]', $result['user']['password']);
        $this->assertSame('[FILTERED]', $result['API_KEY']);
        $this->assertSame('[FILTERED]', $result['nested']['deep']['token']);
        $this->assertSame('a@b.com', $result['user']['email']);
    }

    public function test_mascara_por_wildcard(): void
    {
        $result = $this->redactor()->scrub(['secret_answer' => 'x', 'secretive' => 'y']);

        $this->assertSame('[FILTERED]', $result['secret_answer']);
        $this->assertSame('[FILTERED]', $result['secretive']);
    }

    public function test_normaliza_e_mascara_headers(): void
    {
        $result = $this->redactor()->scrubHeaders([
            'Authorization' => ['Bearer abc'],
            'Content-Type' => ['application/json'],
            'Cookie' => ['session=1'],
        ]);

        $this->assertSame('[FILTERED]', $result['authorization']);
        $this->assertSame('[FILTERED]', $result['cookie']);
        $this->assertSame('application/json', $result['content-type']);
    }

    public function test_trunca_strings_longas(): void
    {
        $result = $this->redactor(maxString: 10)->scrub(['sql' => str_repeat('a', 50)]);

        $this->assertSame(str_repeat('a', 10).'…', $result['sql']);
    }

    public function test_limita_itens_de_array(): void
    {
        $result = $this->redactor(maxItems: 3)->scrub(['list' => range(1, 10)]);

        $this->assertCount(4, $result['list']);
        $this->assertSame('[7 itens omitidos]', $result['list']['...']);
    }

    public function test_limita_profundidade(): void
    {
        $deep = ['l1' => ['l2' => ['l3' => ['l4' => 'fundo']]]];

        $result = $this->redactor(maxDepth: 2)->scrub($deep);

        $this->assertSame('[MAX_DEPTH]', $result['l1']['l2']['l3']);
    }

    public function test_normaliza_objetos_e_enums(): void
    {
        $result = $this->redactor()->scrub([
            'date' => new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')),
            'obj' => new class
            {
                public function toArray(): array
                {
                    return ['ok' => true];
                }
            },
        ]);

        $this->assertStringStartsWith('2026-01-01', $result['date']);
        $this->assertSame(['ok' => true], $result['obj']);
    }
}
