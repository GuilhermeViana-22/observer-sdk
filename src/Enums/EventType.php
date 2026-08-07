<?php

declare(strict_types=1);

namespace Observer\Enums;

/**
 * Vocabulário fechado do protocolo de eventos do Observer.
 *
 * O valor de cada case é o contrato com o Observer Server: nunca renomeie
 * um case existente sem versionar o protocolo.
 */
enum EventType: string
{
    case Exception = 'exception';
    case Log = 'log';
    case Query = 'query';
    case Request = 'request';
    case HttpClient = 'http_client';
    case Queue = 'queue';
    case Schedule = 'schedule';
    case Cache = 'cache';
    case Command = 'command';
    case Metric = 'metric';
    case Custom = 'custom';

    /**
     * Chave usada para procurar a configuração do collector correspondente.
     */
    public function configKey(): string
    {
        return match ($this) {
            self::Exception => 'exceptions',
            self::Log => 'logs',
            self::Query => 'queries',
            self::Request => 'requests',
            self::HttpClient => 'http_client',
            self::Queue => 'queue',
            self::Schedule => 'schedule',
            self::Cache => 'cache',
            self::Command => 'commands',
            self::Metric, self::Custom => 'custom',
        };
    }
}
