<?php

declare(strict_types=1);

namespace Observer\Collectors;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Observer\DTO\Payloads\CachePayload;
use Observer\Enums\EventType;
use Observer\Enums\Severity;

/**
 * Captura hit, miss, escrita e remoção de chaves do cache.
 *
 * É o collector de maior volume em aplicações reais — por isso a config
 * traz sample_rate de 0.1 para 'cache' por padrão.
 */
final class CacheCollector extends AbstractCollector
{
    public function name(): string
    {
        return 'cache';
    }

    public function register(): void
    {
        $this->listen(CacheHit::class, function (CacheHit $event): void {
            $this->push(CachePayload::OP_HIT, $event->key, $event->storeName, $event->tags);
        });

        $this->listen(CacheMissed::class, function (CacheMissed $event): void {
            $this->push(CachePayload::OP_MISS, $event->key, $event->storeName, $event->tags);
        });

        $this->listen(KeyWritten::class, function (KeyWritten $event): void {
            $this->push(CachePayload::OP_WRITE, $event->key, $event->storeName, $event->tags, $event->seconds);
        });

        $this->listen(KeyForgotten::class, function (KeyForgotten $event): void {
            $this->push(CachePayload::OP_FORGET, $event->key, $event->storeName, $event->tags);
        });
    }

    /**
     * @param list<string> $tags
     */
    private function push(string $operation, string $key, ?string $store, array $tags = [], ?int $ttl = null): void
    {
        $this->record(
            EventType::Cache,
            "cache.{$operation} {$key}",
            new CachePayload(
                operation: $operation,
                key: $key,
                store: $store,
                ttl: $ttl,
                tags: array_values($tags),
            ),
            Severity::Debug,
        );
    }
}
