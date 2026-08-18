<?php

declare(strict_types=1);

namespace Observer\Tests\Unit;

use Observer\Support\Configuration;
use PHPUnit\Framework\TestCase;

/**
 * Uma variável declarada e vazia no .env — `OBSERVER_DSN=` — chega aqui como
 * string vazia, nunca como null. Tratá-la como valor configurado foi a causa
 * do aviso indevido de DSN ignorado.
 */
final class ConfigurationTest extends TestCase
{
    public function test_string_vazia_equivale_a_ausente(): void
    {
        $config = Configuration::fromArray([
            'transport' => ['http' => ['dsn' => '', 'endpoint' => '   ', 'api_key' => null]],
            'project' => '',
        ]);

        $this->assertNull($config->string('transport.http.dsn'));
        $this->assertNull($config->string('transport.http.endpoint'));
        $this->assertNull($config->string('transport.http.api_key'));
        $this->assertSame('laravel', $config->string('project', 'laravel'));
        $this->assertSame('padrao', $config->string('inexistente', 'padrao'));
    }

    public function test_string_preserva_o_valor_original(): void
    {
        $config = Configuration::fromArray(['mask' => ' [FILTERED] ', 'porta' => 8080]);

        $this->assertSame(' [FILTERED] ', $config->string('mask'));
        $this->assertSame('8080', $config->string('porta'));
    }

    public function test_bool_vazio_nao_desliga_o_sdk(): void
    {
        $config = Configuration::fromArray(['enabled' => '', 'debug' => '']);

        $this->assertTrue($config->bool('enabled', true));
        $this->assertFalse($config->debug());
        $this->assertTrue($config->isEnabled());
    }

    public function test_bool_continua_lendo_as_formas_do_env(): void
    {
        $config = Configuration::fromArray(['a' => 'true', 'b' => 'false', 'c' => '1', 'd' => '0', 'e' => false]);

        $this->assertTrue($config->bool('a'));
        $this->assertFalse($config->bool('b', true));
        $this->assertTrue($config->bool('c'));
        $this->assertFalse($config->bool('d', true));
        $this->assertFalse($config->bool('e', true));
    }

    public function test_numericos_vazios_caem_no_default(): void
    {
        $config = Configuration::fromArray(['buffer' => ['size' => ''], 'sample_rate' => '']);

        $this->assertSame(50, $config->int('buffer.size', 50));
        $this->assertSame(1.0, $config->float('sample_rate', 1.0));
    }
}
