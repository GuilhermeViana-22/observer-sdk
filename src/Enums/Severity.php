<?php

declare(strict_types=1);

namespace Observer\Enums;

use Psr\Log\LogLevel;

/**
 * Os oito níveis de severidade da PSR-3, ordenados por gravidade.
 */
enum Severity: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
    case Alert = 'alert';
    case Emergency = 'emergency';

    /**
     * Peso numérico — quanto maior, mais grave.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Debug => 100,
            self::Info => 200,
            self::Notice => 250,
            self::Warning => 300,
            self::Error => 400,
            self::Critical => 500,
            self::Alert => 550,
            self::Emergency => 600,
        };
    }

    public function isAtLeast(self $minimum): bool
    {
        return $this->weight() >= $minimum->weight();
    }

    /**
     * Converte um nível PSR-3 (string) ou um nível numérico do Monolog.
     */
    public static function fromPsrLevel(string|int|self $level): self
    {
        if ($level instanceof self) {
            return $level;
        }

        if (is_int($level)) {
            return self::fromMonologLevel($level);
        }

        return self::tryFrom(strtolower($level)) ?? match (strtolower($level)) {
            LogLevel::EMERGENCY => self::Emergency,
            LogLevel::ALERT => self::Alert,
            LogLevel::CRITICAL => self::Critical,
            'err' => self::Error,
            'warn' => self::Warning,
            default => self::Info,
        };
    }

    /**
     * Monolog usa os valores numéricos do RFC 5424 (100..600).
     */
    public static function fromMonologLevel(int $level): self
    {
        return match (true) {
            $level >= 600 => self::Emergency,
            $level >= 550 => self::Alert,
            $level >= 500 => self::Critical,
            $level >= 400 => self::Error,
            $level >= 300 => self::Warning,
            $level >= 250 => self::Notice,
            $level >= 200 => self::Info,
            default => self::Debug,
        };
    }
}
