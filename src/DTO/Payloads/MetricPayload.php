<?php

declare(strict_types=1);

namespace Observer\DTO\Payloads;

use Observer\Contracts\Payload;

final class MetricPayload implements Payload
{
    public const TYPE_GAUGE = 'gauge';

    public const TYPE_COUNTER = 'counter';

    public const TYPE_TIMING = 'timing';

    /**
     * @param array<string, scalar> $tags
     */
    public function __construct(
        public readonly string $name,
        public readonly float $value,
        public readonly string $metricType = self::TYPE_GAUGE,
        public readonly ?string $unit = null,
        public readonly array $tags = [],
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'value' => $this->value,
            'metric_type' => $this->metricType,
            'unit' => $this->unit,
            'tags' => $this->tags ?: null,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
