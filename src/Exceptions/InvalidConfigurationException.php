<?php

declare(strict_types=1);

namespace Observer\Exceptions;

final class InvalidConfigurationException extends ObserverException
{
    public static function missing(string $key): self
    {
        return new self("Observer: a chave de configuração [observer.{$key}] é obrigatória.");
    }

    public static function invalid(string $key, string $expected): self
    {
        return new self("Observer: [observer.{$key}] inválido — esperado {$expected}.");
    }
}
