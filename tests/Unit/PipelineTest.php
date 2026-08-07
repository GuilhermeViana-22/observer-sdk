<?php

declare(strict_types=1);

namespace Observer\Tests\Unit;

use Observer\Contracts\EventProcessor;
use Observer\DTO\Event;
use Observer\Enums\EventType;
use Observer\Enums\Severity;
use Observer\Pipeline\EventPipeline;
use Observer\Pipeline\Processors\BeforeSendProcessor;
use Observer\Pipeline\Processors\EnabledProcessor;
use Observer\Pipeline\Processors\IgnoreProcessor;
use Observer\Pipeline\Processors\SamplingProcessor;
use Observer\Support\Configuration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PipelineTest extends TestCase
{
    public function test_encadeia_processors_em_ordem(): void
    {
        $pipeline = new EventPipeline([
            $this->processor(fn (Event $e) => $e->withTags(['a' => '1'])),
            $this->processor(fn (Event $e) => $e->withTags(['b' => '2'])),
        ]);

        $result = $pipeline->process(Event::make(EventType::Custom, 'x'));

        $this->assertSame(['a' => '1', 'b' => '2'], $result?->tags);
    }

    public function test_curto_circuita_no_primeiro_descarte(): void
    {
        $chamou = false;

        $pipeline = new EventPipeline([
            $this->processor(fn () => null),
            $this->processor(function (Event $e) use (&$chamou) {
                $chamou = true;

                return $e;
            }),
        ]);

        $this->assertNull($pipeline->process(Event::make(EventType::Custom, 'x')));
        $this->assertFalse($chamou);
        $this->assertSame(1, array_sum($pipeline->drops()));
    }

    public function test_processor_que_lanca_excecao_nao_derruba_a_pipeline(): void
    {
        $pipeline = new EventPipeline([
            $this->processor(fn () => throw new RuntimeException('boom')),
            $this->processor(fn (Event $e) => $e->withTags(['ok' => '1'])),
        ]);

        $result = $pipeline->process(Event::make(EventType::Custom, 'x'));

        $this->assertSame(['ok' => '1'], $result?->tags);
    }

    public function test_enabled_processor_descarta_quando_sdk_desligado(): void
    {
        $processor = new EnabledProcessor(Configuration::fromArray(['enabled' => false]));

        $this->assertNull($processor->process(Event::make(EventType::Custom, 'x')));
    }

    public function test_enabled_processor_descarta_ambiente_nao_listado(): void
    {
        $processor = new EnabledProcessor(Configuration::fromArray([
            'enabled' => true,
            'environment' => 'local',
            'environments' => ['production'],
        ]));

        $this->assertNull($processor->process(Event::make(EventType::Custom, 'x')));
    }

    public function test_enabled_processor_descarta_collector_desligado(): void
    {
        $processor = new EnabledProcessor(Configuration::fromArray([
            'enabled' => true,
            'collectors' => ['queries' => ['enabled' => false]],
        ]));

        $this->assertNull($processor->process(Event::make(EventType::Query, 'select 1')));
        $this->assertNotNull($processor->process(Event::make(EventType::Custom, 'x')));
    }

    public function test_ignore_processor_descarta_exception_da_blacklist_e_subclasses(): void
    {
        $processor = new IgnoreProcessor(Configuration::fromArray([
            'collectors' => ['exceptions' => ['ignore' => [\LogicException::class]]],
        ]));

        $ignorada = Event::make(EventType::Exception, 'x', ['class' => \DomainException::class]);
        $mantida = Event::make(EventType::Exception, 'x', ['class' => RuntimeException::class]);

        $this->assertNull($processor->process($ignorada));
        $this->assertNotNull($processor->process($mantida));
    }

    public function test_ignore_processor_aplica_wildcard_em_rotas(): void
    {
        $processor = new IgnoreProcessor(Configuration::fromArray([
            'collectors' => ['requests' => ['ignore_paths' => ['horizon*']]],
        ]));

        $ignorada = Event::make(EventType::Request, 'GET', ['url' => 'https://app.test/horizon/api/stats']);
        $mantida = Event::make(EventType::Request, 'GET', ['url' => 'https://app.test/pedidos']);

        $this->assertNull($processor->process($ignorada));
        $this->assertNotNull($processor->process($mantida));
    }

    public function test_sampling_nunca_descarta_exceptions_nem_erros(): void
    {
        $processor = new SamplingProcessor(Configuration::fromArray(['sample_rate' => 0.0]));

        $exception = Event::make(EventType::Exception, 'x', level: Severity::Error);
        $logDeErro = Event::make(EventType::Log, 'x', level: Severity::Critical);
        $query = Event::make(EventType::Query, 'x', level: Severity::Debug);

        $this->assertNotNull($processor->process($exception));
        $this->assertNotNull($processor->process($logDeErro));
        $this->assertNull($processor->process($query));
    }

    public function test_before_send_pode_modificar_ou_descartar(): void
    {
        $modifica = new BeforeSendProcessor(fn (Event $e) => $e->withTags(['hook' => '1']));
        $descarta = new BeforeSendProcessor(fn () => null);
        $invalido = new BeforeSendProcessor(fn () => 'não é um evento');

        $event = Event::make(EventType::Custom, 'x');

        $this->assertSame(['hook' => '1'], $modifica->process($event)?->tags);
        $this->assertNull($descarta->process($event));
        // Retorno inválido é tratado como "não mexa", nunca como descarte.
        $this->assertSame($event, $invalido->process($event));
    }

    /**
     * @param callable(Event): (Event|null) $handler
     */
    private function processor(callable $handler): EventProcessor
    {
        return new class($handler) implements EventProcessor
        {
            /** @var callable(Event): (Event|null) */
            private $handler;

            public function __construct(callable $handler)
            {
                $this->handler = $handler;
            }

            public function process(Event $event): ?Event
            {
                return ($this->handler)($event);
            }
        };
    }
}
