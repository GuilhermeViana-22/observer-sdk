<?php

declare(strict_types=1);

namespace Observer\Tests\Unit;

use Observer\Enums\Severity;
use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    public function test_ordena_por_gravidade(): void
    {
        $this->assertTrue(Severity::Error->isAtLeast(Severity::Warning));
        $this->assertTrue(Severity::Emergency->isAtLeast(Severity::Debug));
        $this->assertFalse(Severity::Debug->isAtLeast(Severity::Info));
        $this->assertTrue(Severity::Info->isAtLeast(Severity::Info));
    }

    public function test_converte_niveis_psr3(): void
    {
        $this->assertSame(Severity::Critical, Severity::fromPsrLevel('critical'));
        $this->assertSame(Severity::Error, Severity::fromPsrLevel('ERROR'));
        $this->assertSame(Severity::Error, Severity::fromPsrLevel('err'));
        $this->assertSame(Severity::Warning, Severity::fromPsrLevel('warn'));
        $this->assertSame(Severity::Info, Severity::fromPsrLevel('desconhecido'));
    }

    public function test_converte_niveis_numericos_do_monolog(): void
    {
        $this->assertSame(Severity::Debug, Severity::fromMonologLevel(100));
        $this->assertSame(Severity::Notice, Severity::fromMonologLevel(250));
        $this->assertSame(Severity::Error, Severity::fromMonologLevel(400));
        $this->assertSame(Severity::Emergency, Severity::fromMonologLevel(600));
    }
}
