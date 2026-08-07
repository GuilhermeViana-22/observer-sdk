<?php

declare(strict_types=1);

namespace Observer\Tests\Unit;

use Observer\Buffer\EventBuffer;
use Observer\DTO\Event;
use Observer\Enums\EventType;
use Observer\Exceptions\TransportException;
use Observer\Serializers\JsonSerializer;
use Observer\Support\Configuration;
use Observer\Transport\FileTransport;
use Observer\Transport\MemoryTransport;
use Observer\Transport\NullTransport;
use Observer\Transport\TransportManager;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/observer-test-'.uniqid();
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tmp);

        parent::tearDown();
    }

    public function test_null_transport_descarta_tudo(): void
    {
        $transport = new NullTransport;
        $transport->send($this->event());
        $transport->sendBatch([$this->event(), $this->event()]);

        $this->assertSame(3, $transport->discardedCount());
        $this->assertTrue($transport->flush());
    }

    public function test_memory_transport_permite_asserts(): void
    {
        $transport = new MemoryTransport;
        $transport->send(Event::make(EventType::Log, 'a'));
        $transport->send(Event::make(EventType::Exception, 'b'));

        $this->assertSame(2, $transport->count());
        $this->assertSame(1, $transport->count(EventType::Exception));
        $this->assertSame('b', $transport->last(EventType::Exception)?->message);

        $transport->clear();
        $this->assertSame(0, $transport->count());
    }

    public function test_file_transport_grava_json_lines(): void
    {
        $path = $this->tmp.'/observer.ndjson';
        $transport = new FileTransport($path);

        $transport->sendBatch([Event::make(EventType::Log, 'primeiro'), Event::make(EventType::Log, 'segundo')]);

        $lines = array_filter(explode(PHP_EOL, (string) file_get_contents($path)));

        $this->assertCount(2, $lines);

        $decoded = json_decode((string) reset($lines), true);
        $this->assertSame('primeiro', $decoded['message']);
        $this->assertSame('log', $decoded['type']);
    }

    public function test_file_transport_rotaciona_por_tamanho(): void
    {
        $path = $this->tmp.'/observer.ndjson';
        $transport = new FileTransport($path, maxSize: 100, maxFiles: 2);

        $transport->send($this->event());
        $transport->send($this->event());
        $transport->send($this->event());

        $this->assertFileExists($path);
        $this->assertFileExists($path.'.1');
    }

    public function test_file_transport_nao_lanca_em_caminho_invalido(): void
    {
        $transport = new FileTransport('/proc/observer-impossivel/x.ndjson');

        $transport->send($this->event());

        $this->assertTrue($transport->flush());
    }

    public function test_manager_resolve_drivers_e_recusa_desconhecido(): void
    {
        $manager = new TransportManager(Configuration::fromArray([
            'transport' => ['driver' => 'memory', 'file' => ['path' => $this->tmp.'/x.ndjson']],
        ]));

        $this->assertInstanceOf(MemoryTransport::class, $manager->driver());
        $this->assertInstanceOf(FileTransport::class, $manager->driver('file'));
        $this->assertInstanceOf(NullTransport::class, $manager->driver('null'));
        // Instâncias são memoizadas por driver.
        $this->assertSame($manager->driver(), $manager->driver());

        $this->expectException(TransportException::class);
        $manager->driver('carteiro');
    }

    public function test_manager_aceita_driver_customizado(): void
    {
        $manager = new TransportManager(Configuration::fromArray(['transport' => ['driver' => 'custom']]));
        $manager->extend('custom', static fn () => new MemoryTransport);

        $this->assertInstanceOf(MemoryTransport::class, $manager->driver());
    }

    public function test_buffer_drena_ao_atingir_o_limite(): void
    {
        $transport = new MemoryTransport;
        $buffer = new EventBuffer($transport, maxSize: 3);

        $buffer->add($this->event());
        $buffer->add($this->event());

        $this->assertSame(0, $transport->count());
        $this->assertSame(2, $buffer->count());

        $buffer->add($this->event());

        $this->assertSame(3, $transport->count());
        $this->assertTrue($buffer->isEmpty());
    }

    public function test_buffer_desligado_envia_direto(): void
    {
        $transport = new MemoryTransport;
        $buffer = new EventBuffer($transport, maxSize: 50, enabled: false);

        $buffer->add($this->event());

        $this->assertSame(1, $transport->count());
    }

    public function test_serializer_gera_lote_e_linhas(): void
    {
        $serializer = new JsonSerializer;
        $events = [Event::make(EventType::Log, 'a'), Event::make(EventType::Log, 'b')];

        $batch = json_decode($serializer->serializeBatch($events), true);

        $this->assertSame(2, $batch['count']);
        $this->assertCount(2, $batch['events']);
        $this->assertSame('application/json', $serializer->contentType());

        $lines = array_filter(explode(PHP_EOL, $serializer->serializeLines($events)));
        $this->assertCount(2, $lines);
    }

    public function test_serializer_sobrevive_a_utf8_invalido(): void
    {
        $event = Event::make(EventType::Log, 'ok', ['raw' => "\xB1\x31"]);

        $json = (new JsonSerializer)->serialize($event);

        $this->assertJson($json);
        $this->assertStringContainsString('"type":"log"', $json);
    }

    private function event(): Event
    {
        return Event::make(EventType::Custom, 'evento-de-teste', ['data' => str_repeat('x', 60)]);
    }
}
