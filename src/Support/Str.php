<?php

declare(strict_types=1);

namespace Observer\Support;

/**
 * Utilitários de string sem dependência do Illuminate.
 */
final class Str
{
    /**
     * Casamento por wildcard (`horizon*`) ou por regex quando o padrão vier
     * delimitado (`/^select .* from/i`).
     *
     * @param list<string> $patterns
     */
    public static function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($value, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $value, string $pattern): bool
    {
        if ($pattern === $value) {
            return true;
        }

        if (self::isRegex($pattern)) {
            return @preg_match($pattern, $value) === 1;
        }

        $quoted = preg_quote($pattern, '#');

        return preg_match('#^'.str_replace('\*', '.*', $quoted).'\z#u', $value) === 1;
    }

    public static function truncate(string $value, int $limit, string $suffix = '…'): string
    {
        if ($limit <= 0 || mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit).$suffix;
    }

    /**
     * Um padrão é tratado como regex quando começa e termina pelo mesmo
     * delimitador não-alfanumérico (com flags opcionais).
     */
    private static function isRegex(string $pattern): bool
    {
        return (bool) preg_match('/^([#\/~%|]).*\1[imsxuADSUXJn]*$/s', $pattern);
    }
}
