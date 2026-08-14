<?php

declare(strict_types=1);

namespace Observer\DTO\Payloads;

use Observer\Contracts\Payload;

final class CachePayload implements Payload
{
    public const OP_HIT = 'hit';

    public const OP_MISS = 'miss';

    public const OP_WRITE = 'write';

    public const OP_FORGET = 'forget';

    /**
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $key,
        public readonly ?string $store = null,
        public readonly ?int $ttl = null,
        public readonly array $tags = [],
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'operation' => $this->operation,
            'key' => $this->key,
            'store' => $this->store,
            'ttl' => $this->ttl,
            'tags' => $this->tags ?: null,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
