<?php

declare(strict_types=1);

namespace Observer\Log;

use Monolog\Logger;
use Observer\Contracts\ClientInterface;
use Observer\Enums\Severity;

/**
 * Driver de canal para o config/logging.php:
 *
 *   'observer' => [
 *       'driver' => 'observer',
 *       'level'  => 'error',
 *   ],
 *
 * Registrado no boot do provider via Log::extend('observer', ...).
 */
final class ObserverLogChannel
{
    public function __construct(private readonly ClientInterface $observer) {}

    /**
     * @param array<string, mixed> $config
     */
    public function __invoke(array $config): Logger
    {
        $level = $config['level'] ?? 'debug';

        return new Logger(
            $config['name'] ?? 'observer',
            [new ObserverHandler(
                observer: $this->observer,
                level: Severity::fromPsrLevel(is_string($level) ? $level : 'debug')->value,
                bubble: (bool) ($config['bubble'] ?? true),
            )],
        );
    }
}
