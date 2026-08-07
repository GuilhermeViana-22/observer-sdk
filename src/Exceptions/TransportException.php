<?php

declare(strict_types=1);

namespace Observer\Exceptions;

final class TransportException extends ObserverException
{
    public static function unsupportedDriver(string $driver): self
    {
        return new self("Observer: transporte [{$driver}] não é suportado.");
    }

    public static function notWritable(string $path): self
    {
        return new self("Observer: não foi possível escrever em [{$path}].");
    }

    public static function notImplemented(string $driver): self
    {
        return new self("Observer: o transporte [{$driver}] ainda não está disponível nesta versão do SDK.");
    }
}
