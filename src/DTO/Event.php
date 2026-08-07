<?php

declare(strict_types=1);

namespace Observer\DTO;

use Observer\Contracts\Payload;
use Observer\Enums\EventType;
use Observer\Enums\Severity;
use Observer\Support\Clock;
use Observer\Support\Uuid;

/**
 * Envelope imutável de todo evento produzido pelo SDK.
 *
 * O payload é específico do tipo; o contexto é transversal (runtime, usuário,
 * servidor, trace) e é preenchido pela pipeline, não pelo collector.
 */
final readonly class Event
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @param array<string, scalar> $tags
     */
    public function __construct(
        public string $id,
        public EventType $type,
        public Severity $level,
        public string $message,
        public float $timestamp,
        public array $payload = [],
        public array $context = [],
        public array $tags = [],
    ) {}

    /**
     * @param array<string, mixed>|Payload $payload
     * @param array<string, scalar> $tags
     */
    public static function make(
        EventType $type,
        string $message,
        array|Payload $payload = [],
        Severity $level = Severity::Info,
        array $tags = [],
    ): self {
        return new self(
            id: Uuid::v4(),
            type: $type,
            level: $level,
            message: $message,
            timestamp: Clock::now(),
            payload: $payload instanceof Payload ? $payload->toArray() : $payload,
            tags: $tags,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function withPayload(array $payload): self
    {
        return $this->clone(payload: $payload);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): self
    {
        return $this->clone(context: $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function mergeContext(array $context): self
    {
        return $this->clone(context: array_replace_recursive($this->context, $context));
    }

    /**
     * @param array<string, scalar> $tags
     */
    public function withTags(array $tags): self
    {
        return $this->clone(tags: array_merge($this->tags, $tags));
    }

    public function withLevel(Severity $level): self
    {
        return $this->clone(level: $level);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'level' => $this->level->value,
            'message' => $this->message,
            'timestamp' => $this->timestamp,
            'occurred_at' => Clock::iso8601($this->timestamp),
            'payload' => $this->payload,
            'context' => $this->context,
            'tags' => $this->tags,
        ];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed>|null $context
     * @param array<string, scalar>|null $tags
     */
    private function clone(
        ?array $payload = null,
        ?array $context = null,
        ?array $tags = null,
        ?Severity $level = null,
    ): self {
        return new self(
            id: $this->id,
            type: $this->type,
            level: $level ?? $this->level,
            message: $this->message,
            timestamp: $this->timestamp,
            payload: $payload ?? $this->payload,
            context: $context ?? $this->context,
            tags: $tags ?? $this->tags,
        );
    }
}
