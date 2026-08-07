<?php

declare(strict_types=1);

namespace Observer\Support;

use Random\RandomException;

/**
 * Gerador de UUID v4 sem dependências externas.
 */
final class Uuid
{
    public static function v4(): string
    {
        try {
            $bytes = random_bytes(16);
        } catch (RandomException) {
            // Fallback determinístico: um ID degradado é melhor que perder o evento.
            $bytes = pack('N4', mt_rand(), mt_rand(), mt_rand(), mt_rand());
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
