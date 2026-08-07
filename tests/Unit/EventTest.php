<?php

declare(strict_types=1);

namespace Observer\Tests\Unit;

use Observer\DTO\Event;
use Observer\DTO\Payloads\MetricPayload;
use Observer\Enums\EventType;
use Observer\Enums\Severity;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function test_make_gera_id_unico_e_timestamp(): void
    {
        $a = Event::make(EventType::Custom, 'a');
        $b = Event::make(EventType::Custom, 'b');

        $this->assertNotSame($a->id, $b->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f\-]{36}$/', $a->id);
        $this->assertGreaterThan(0, $a->timestamp);
    }

    public function test_aceita_payload_tipado(): void
    {
        $event = Event::make(
            EventType::Metric,
            'queue.depth',
            new MetricPayload('queue.depth', 12.0, tags: ['queue' => 'default']),
        );

        $this->assertSame('queue.depth', $event->payload['name']);
        $this->assertSame(12.0, $event->payload['value']);
        $this->assertSame(['queue' => 'default'], $event->payload['tags']);
    }

    public function test_with_methods_nao_mutam_o_original(): void
    {
        $original = Event::make(EventType::Custom, 'evento', ['a' => 1]);

        $modified = $original
            ->withPayload(['a' => 2])
            ->withTags(['x' => 'y'])
            ->withLevel(Severity::Critical);

        $this->assertSame(['a' => 1], $original->payload);
        $this->assertSame([], $original->tags);
        $this->assertSame(Severity::Info, $original->level);

        $this->assertSame(['a' => 2], $modified->payload);
        $this->assertSame(['x' => 'y'], $modified->tags);
        $this->assertSame(Severity::Critical, $modified->level);
        $this->assertSame($original->id, $modified->id);
    }

    public function test_merge_context_e_recursivo(): void
    {
        $event = Event::make(EventType::Custom, 'e')
            ->withContext(['user' => ['id' => 1, 'role' => 'admin']])
            ->mergeContext(['user' => ['role' => 'owner'], 'trace_id' => 'abc']);

        $this->assertSame(['id' => 1, 'role' => 'owner'], $event->context['user']);
        $this->assertSame('abc', $event->context['trace_id']);
    }

    public function test_to_array_exporta_o_protocolo_completo(): void
    {
        $array = Event::make(EventType::Log, 'oi', level: Severity::Warning)->toArray();

        $this->assertSame('log', $array['type']);
        $this->assertSame('warning', $array['level']);
        $this->assertSame('oi', $array['message']);
        $this->assertArrayHasKey('occurred_at', $array);
        $this->assertStringEndsWith('Z', $array['occurred_at']);
    }
}
