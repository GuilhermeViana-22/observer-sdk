<?php

declare(strict_types=1);

namespace Observer\Support;

/**
 * Fonte de tempo do SDK. Centralizada para poder ser congelada em testes.
 */
final class Clock
{
    private static ?float $frozenAt = null;

    public static function now(): float
    {
        return self::$frozenAt ?? microtime(true);
    }

    public static function iso8601(?float $timestamp = null): string
    {
        $timestamp ??= self::now();
        $seconds = (int) $timestamp;
        $micro = (int) round(($timestamp - $seconds) * 1_000_000);

        return sprintf('%s.%06dZ', gmdate('Y-m-d\TH:i:s', $seconds), $micro);
    }

    public static function freeze(float $timestamp): void
    {
        self::$frozenAt = $timestamp;
    }

    public static function unfreeze(): void
    {
        self::$frozenAt = null;
    }
}
