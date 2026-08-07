<?php

declare(strict_types=1);

namespace Observer\DTO\Payloads;

use Observer\Contracts\Payload;

final readonly class MetricPayload implements Payload
{
    public const TYPE_GAUGE = 'gauge';

    public const TYPE_COUNTER = 'counter';

    public const TYPE_TIMING = 'timing';

    /**
     * @param array<string, scalar> $tags
     */
    public function __construct(
        public string $name,
        public float $value,
        public string $metricType = self::TYPE_GAUGE,
        public ?string $unit = null,
        public array $tags = [],
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
