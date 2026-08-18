<?php

declare(strict_types=1);

namespace Observer\Tests\Feature;

use Observer\Buffer\EventBuffer;
use Observer\Contracts\ClientInterface;
use Observer\Enums\EventType;
use Observer\Facades\Observer as ObserverFacade;
use Observer\Observer;
use Observer\Pipeline\EventPipeline;
use Observer\Support\Configuration;
use Observer\Tests\TestCase;
use Observer\Transport\FileTransport;
use Observer\Transport\MemoryTransport;
use Observer\Transport\NullTransport;
use Observer\Transport\TransportManager;
use RuntimeException;

final class ServiceProviderTest extends TestCase
{
    public function test_registra_os_bindings_do_sdk(): void
    {
        $this->assertInstanceOf(Observer::class, $this->app->make(ClientInterface::class));
        $this->assertInstanceOf(Configuration::class, $this->app->make(Configuration::class));
        $this->assertInstanceOf(TransportManager::class, $this->app->make(TransportManager::class));
        $this->assertInstanceOf(EventBuffer::class, $this->app->make(EventBuffer::class));
        $this->assertInstanceOf(EventPipeline::class, $this->app->make(EventPipeline::class));

        // Singletons de verdade: a mesma instância em todo o ciclo.
        $this->assertSame($this->app->make(ClientInterface::class), $this->app->make(Observer::class));
    }

    public function test_publica_a_configuracao_com_defaults(): void
    {
        $config = $this->app->make(Configuration::class);

        $this->assertTrue($config->isEnabled());
        $this->assertTrue($config->collectorEnabled('exceptions'));
        $this->assertSame('[FILTERED]', $config->string('scrubbing.mask'));
        $this->assertGreaterThan(0, $config->int('limits.max_string_length'));
    }

    public function test_a_pipeline_tem_os_processors_na_ordem_esperada(): void
    {
        $classes = array_map(
            static fn (object $p): string => class_basename($p),
            $this->app->make(EventPipeline::class)->processors(),
        );

        $this->assertSame([
            'EnabledProcessor',
            'IgnoreProcessor',
            'SamplingProcessor',
            'ContextProcessor',
            'ScrubbingProcessor',
            'BeforeSendProcessor',
        ], $classes);
    }

    public function test_facade_e_helper_resolvem_o_mesmo_cliente(): void
    {
        $this->assertSame(
            $this->app->make(ClientInterface::class),
            ObserverFacade::getFacadeRoot(),
        );

        $this->assertSame($this->app->make(ClientInterface::class), observer());
    }

    public function test_fake_troca_o_transporte_por_memoria(): void
    {
        $fake = ObserverFacade::fake();

        $this->assertInstanceOf(MemoryTransport::class, $fake->transport());

        ObserverFacade::capture(new RuntimeException('falhou'));

        $fake->assertCaptured(RuntimeException::class, times: 1);
    }

    public function test_api_publica_produz_os_quatro_tipos_de_evento(): void
    {
        $fake = ObserverFacade::fake();

        ObserverFacade::capture(new RuntimeException('erro de negócio'));
        ObserverFacade::log('warning', 'algo estranho');
        ObserverFacade::event('checkout.completed', ['total' => 199.90]);
        ObserverFacade::metric('queue.depth', 128.0, ['queue' => 'default']);

        $fake->assertCaptured(RuntimeException::class)
            ->assertLogged('algo estranho')
            ->assertEvent('checkout.completed')
            ->assertMetric('queue.depth', 128.0)
            ->assertCount(4);
    }

    public function test_sdk_desligado_vira_no_op(): void
    {
        $fake = ObserverFacade::fake();
        config()->set('observer.enabled', false);
        $this->app->forgetInstance(Configuration::class);
        $this->app->forgetInstance(Observer::class);
        $this->app->forgetInstance(ClientInterface::class);

        $this->assertNull($this->app->make(ClientInterface::class)->log('error', 'ignorado'));
        $fake->assertNothingRecorded();
    }

    public function test_escopo_enriquece_o_contexto_dos_eventos(): void
    {
        $fake = ObserverFacade::fake();

        ObserverFacade::withUser(['id' => 42, 'email' => 'a@b.com']);
        ObserverFacade::withTags(['tenant' => 'acme']);
        ObserverFacade::breadcrumb('cart', 'item adicionado', ['sku' => 'X1']);
        ObserverFacade::capture(new RuntimeException('boom'));

        $event = $fake->transport()->last(EventType::Exception);

        $this->assertSame(42, $event?->context['user']['id']);
        // PII fora por padrão: o e-mail não vaza.
        $this->assertArrayNotHasKey('email', $event?->context['user'] ?? []);
        $this->assertSame('acme', $event?->tags['tenant']);
        $this->assertSame('cart', $event?->context['breadcrumbs'][0]['category']);
        $this->assertNotEmpty($event?->context['trace_id']);
    }

    public function test_before_send_pode_descartar_eventos(): void
    {
        config()->set('observer.before_send', static fn () => null);
        $fake = ObserverFacade::fake();

        ObserverFacade::log('error', 'nunca chega');

        $fake->assertNothingRecorded();
    }

    /**
     * O crash relatado em produção: `OBSERVER_DSN=` vazio no .env fazia o
     * TransportManager avisar sobre DSN ignorado, e o aviso chamava um método
     * que não existia — derrubando qualquer comando artisan no boot.
     */
    public function test_boot_com_dsn_vazio_nao_derruba_a_aplicacao(): void
    {
        config()->set('observer.transport.driver', 'file');
        config()->set('observer.transport.file.path', sys_get_temp_dir().'/observer-boot-test.ndjson');
        config()->set('observer.transport.http.dsn', '');
        config()->set('observer.transport.http.endpoint', '');
        config()->set('observer.transport.http.api_key', '');

        $this->refreshObserver();

        $transport = $this->app->make(TransportManager::class)->driver();

        $this->assertInstanceOf(FileTransport::class, $transport);
        $this->assertNotNull($this->app->make(ClientInterface::class)->log('info', 'boot ok'));

        @unlink(sys_get_temp_dir().'/observer-boot-test.ndjson');
    }

    /**
     * Mesmo cenário, com o transporte http selecionado e nada preenchido: o
     * envio precisa ficar desligado em vez de tentar uma URL quebrada a cada
     * flush, e ainda assim sem exception nenhuma.
     */
    public function test_transporte_http_sem_credencial_fica_desligado(): void
    {
        config()->set('observer.transport.driver', 'http');
        config()->set('observer.transport.http.dsn', '');
        config()->set('observer.transport.http.endpoint', '');

        $this->refreshObserver();

        $this->assertInstanceOf(NullTransport::class, $this->app->make(TransportManager::class)->driver());
        $this->assertNotNull($this->app->make(ClientInterface::class)->log('info', 'sem envio'));
    }

    private function refreshObserver(): void
    {
        foreach ([Configuration::class, TransportManager::class, EventBuffer::class, Observer::class, ClientInterface::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    public function test_dados_sensiveis_sao_mascarados_ponta_a_ponta(): void
    {
        $fake = ObserverFacade::fake();

        ObserverFacade::event('login', ['user' => 'joao', 'password' => 'segredo123']);

        $event = $fake->transport()->last(EventType::Custom);

        $this->assertSame('[FILTERED]', $event?->payload['data']['password']);
        $this->assertSame('joao', $event?->payload['data']['user']);
    }
}
