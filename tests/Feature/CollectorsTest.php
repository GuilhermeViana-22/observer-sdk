<?php

declare(strict_types=1);

namespace Observer\Tests\Feature;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledTask;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Observer\Enums\EventType;
use Observer\Enums\Severity;
use Observer\Facades\Observer as ObserverFacade;
use Observer\Testing\ObserverFake;
use Observer\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Response;

final class CollectorsTest extends TestCase
{
    private ObserverFake $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = ObserverFacade::fake();
    }

    // -----------------------------------------------------------------
    // Queries
    // -----------------------------------------------------------------

    public function test_captura_queries_com_tempo_conexao_e_bindings(): void
    {
        Event::dispatch($this->queryEvent('select * from users where id = ?', [7], 12.5));

        $event = $this->fake->transport()->last(EventType::Query);

        $this->assertNotNull($event);
        $this->assertSame('select * from users where id = ?', $event->payload['sql']);
        $this->assertSame(12.5, $event->payload['duration_ms']);
        $this->assertSame([7], $event->payload['bindings']);
        $this->assertFalse($event->payload['slow']);
        $this->assertSame(Severity::Debug, $event->level);
    }

    public function test_marca_query_lenta_como_warning(): void
    {
        config()->set('observer.collectors.queries.slow_threshold_ms', 10);
        $this->fake = ObserverFacade::fake();

        Event::dispatch($this->queryEvent('select sleep(1)', [], 50.0));

        $event = $this->fake->transport()->last(EventType::Query);

        $this->assertTrue($event?->payload['slow']);
        $this->assertSame(Severity::Warning, $event?->level);
    }

    public function test_respeita_a_lista_de_queries_ignoradas(): void
    {
        Event::dispatch($this->queryEvent('select * from `migrations`', [], 1.0));

        $this->fake->assertNothingRecorded(EventType::Query);
    }

    public function test_nao_captura_bindings_quando_desligado(): void
    {
        config()->set('observer.collectors.queries.capture_bindings', false);
        $this->fake = ObserverFacade::fake();

        Event::dispatch($this->queryEvent('select * from users where email = ?', ['a@b.com'], 1.0));

        $this->assertSame([], $this->fake->transport()->last(EventType::Query)?->payload['bindings']);
    }

    // -----------------------------------------------------------------
    // Logs
    // -----------------------------------------------------------------

    public function test_captura_logs_da_aplicacao(): void
    {
        Log::warning('disco quase cheio', ['livre' => '2GB']);

        $event = $this->fake->transport()->last(EventType::Log);

        $this->assertSame('disco quase cheio', $event?->message);
        $this->assertSame(Severity::Warning, $event?->level);
        $this->assertSame('2GB', $event?->payload['context']['livre']);
    }

    public function test_respeita_o_nivel_minimo_de_log(): void
    {
        config()->set('observer.collectors.logs.level', 'error');
        $this->fake = ObserverFacade::fake();

        Log::info('irrelevante');
        Log::error('relevante');

        $this->fake->assertCount(1, EventType::Log);
        $this->assertSame('relevante', $this->fake->transport()->last(EventType::Log)?->message);
    }

    public function test_log_com_exception_vira_evento_de_exception(): void
    {
        Log::error('falha no gateway', ['exception' => new RuntimeException('timeout')]);

        $this->fake->assertCaptured(RuntimeException::class, times: 1);
        $this->fake->assertNothingRecorded(EventType::Log);
    }

    // -----------------------------------------------------------------
    // Cache
    // -----------------------------------------------------------------

    public function test_captura_as_quatro_operacoes_de_cache(): void
    {
        Event::dispatch(new CacheHit('redis', 'user:1', 'valor'));
        Event::dispatch(new CacheMissed('redis', 'user:2'));
        Event::dispatch(new KeyWritten('redis', 'user:3', 'valor', 600));
        Event::dispatch(new KeyForgotten('redis', 'user:4'));

        $operacoes = array_map(
            static fn ($e) => $e->payload['operation'],
            $this->fake->events(EventType::Cache),
        );

        $this->assertSame(['hit', 'miss', 'write', 'forget'], $operacoes);
        $this->assertSame(600, $this->fake->events(EventType::Cache)[2]->payload['ttl']);
    }

    public function test_ignora_chaves_internas_do_cache(): void
    {
        Event::dispatch(new CacheHit('redis', 'observer:buffer', 'x'));

        $this->fake->assertNothingRecorded(EventType::Cache);
    }

    // -----------------------------------------------------------------
    // Request
    // -----------------------------------------------------------------

    public function test_captura_a_request_http(): void
    {
        $request = Request::create('https://app.test/pedidos?page=2', 'GET');
        $request->headers->set('User-Agent', 'PHPUnit');
        $request->headers->set('Authorization', 'Bearer segredo');

        Event::dispatch(new RequestHandled(
            $request,
            new Response('ok', 201),
        ));

        $event = $this->fake->transport()->last(EventType::Request);

        $this->assertSame('GET', $event?->payload['method']);
        $this->assertSame(201, $event?->payload['status_code']);
        $this->assertSame(['page' => '2'], $event?->payload['query']);
        $this->assertSame('[FILTERED]', $event?->payload['headers']['authorization']);
        $this->assertGreaterThan(0, $event?->payload['duration_ms']);
        // PII desligado por padrão: IP e user agent não são enviados.
        $this->assertArrayNotHasKey('ip', $event?->payload ?? []);
    }

    public function test_status_5xx_vira_evento_de_erro(): void
    {
        Event::dispatch(new RequestHandled(
            Request::create('https://app.test/falha'),
            new Response('erro', 500),
        ));

        $this->assertSame(Severity::Error, $this->fake->transport()->last(EventType::Request)?->level);
    }

    public function test_ignora_rotas_da_blacklist(): void
    {
        Event::dispatch(new RequestHandled(
            Request::create('https://app.test/horizon/api/stats'),
            new Response('ok', 200),
        ));

        $this->fake->assertNothingRecorded(EventType::Request);
    }

    // -----------------------------------------------------------------
    // HTTP Client
    // -----------------------------------------------------------------

    public function test_captura_chamadas_http_de_saida_com_duracao(): void
    {
        $request = new ClientRequest(new \GuzzleHttp\Psr7\Request('POST', 'https://api.externa.com/v1/pedidos'));

        Event::dispatch(new RequestSending($request));
        Event::dispatch(new ResponseReceived(
            $request,
            new ClientResponse(new \GuzzleHttp\Psr7\Response(200, [], '{"ok":true}')),
        ));

        $event = $this->fake->transport()->last(EventType::HttpClient);

        $this->assertSame('POST', $event?->payload['method']);
        $this->assertSame('api.externa.com', $event?->payload['host']);
        $this->assertSame(200, $event?->payload['status_code']);
        $this->assertFalse($event?->payload['failed']);
        $this->assertNotNull($event?->payload['duration_ms']);
    }

    public function test_captura_falha_de_conexao_como_erro(): void
    {
        $request = new ClientRequest(new \GuzzleHttp\Psr7\Request('GET', 'https://api.offline.com/health'));

        Event::dispatch(new ConnectionFailed($request, new ConnectionException('offline')));

        $event = $this->fake->transport()->last(EventType::HttpClient);

        $this->assertTrue($event?->payload['failed']);
        $this->assertSame('connection_failed', $event?->payload['error']);
        $this->assertSame(Severity::Error, $event?->level);
    }

    // -----------------------------------------------------------------
    // Queue
    // -----------------------------------------------------------------

    public function test_captura_job_processado_com_duracao(): void
    {
        $job = $this->fakeJob();

        Event::dispatch(new JobProcessing('redis', $job));
        Event::dispatch(new JobProcessed('redis', $job));

        $event = $this->fake->transport()->last(EventType::Queue);

        $this->assertSame('processed', $event?->payload['status']);
        $this->assertSame('App\\Jobs\\EnviarEmail', $event?->payload['job']);
        $this->assertSame('emails', $event?->payload['queue']);
        $this->assertSame(1, $event?->payload['attempts']);
        $this->assertNotNull($event?->payload['duration_ms']);
    }

    public function test_captura_job_falho_com_a_exception(): void
    {
        $job = $this->fakeJob();

        Event::dispatch(new JobFailed('redis', $job, new RuntimeException('SMTP recusou')));

        $event = $this->fake->transport()->last(EventType::Queue);

        $this->assertSame('failed', $event?->payload['status']);
        $this->assertSame(RuntimeException::class, $event?->payload['exception']['class']);
        $this->assertSame('SMTP recusou', $event?->payload['exception']['message']);
        $this->assertFalse($event?->payload['exception']['handled']);
        $this->assertSame(Severity::Error, $event?->level);
    }

    public function test_nao_captura_enfileiramento_por_padrao(): void
    {
        Event::dispatch(new JobProcessing('redis', $this->fakeJob()));

        $this->fake->assertNothingRecorded(EventType::Queue);
    }

    // -----------------------------------------------------------------
    // Scheduler
    // -----------------------------------------------------------------

    public function test_captura_tarefa_agendada(): void
    {
        $task = $this->scheduledTask();

        Event::dispatch(new ScheduledTaskStarting($task));
        Event::dispatch(new ScheduledTaskFinished($task, 2.5));

        $eventos = $this->fake->events(EventType::Schedule);

        $this->assertCount(2, $eventos);
        $this->assertSame('started', $eventos[0]->payload['status']);
        $this->assertSame('finished', $eventos[1]->payload['status']);
        $this->assertSame(2500.0, $eventos[1]->payload['duration_ms']);
        $this->assertSame('* * * * *', $eventos[1]->payload['expression']);
    }

    // -----------------------------------------------------------------
    // Commands
    // -----------------------------------------------------------------

    public function test_captura_comandos_artisan(): void
    {
        Event::dispatch(new CommandStarting('app:importar', new ArrayInput([]), new NullOutput));
        Event::dispatch(new CommandFinished('app:importar', new ArrayInput([]), new NullOutput, 0));

        $eventos = $this->fake->events(EventType::Command);

        $this->assertCount(2, $eventos);
        $this->assertSame('finished', $eventos[1]->payload['status']);
        $this->assertSame(0, $eventos[1]->payload['exit_code']);
        $this->assertNotNull($eventos[1]->payload['duration_ms']);
    }

    public function test_comando_com_exit_code_diferente_de_zero_vira_erro(): void
    {
        Event::dispatch(new CommandFinished('app:importar', new ArrayInput([]), new NullOutput, 1));

        $this->assertSame(Severity::Error, $this->fake->transport()->last(EventType::Command)?->level);
    }

    public function test_ignora_comandos_da_blacklist(): void
    {
        Event::dispatch(new CommandFinished('schedule:run', new ArrayInput([]), new NullOutput, 0));

        $this->fake->assertNothingRecorded(EventType::Command);
    }

    // -----------------------------------------------------------------
    // Collector desligado
    // -----------------------------------------------------------------

    public function test_collector_desligado_nao_produz_eventos(): void
    {
        config()->set('observer.collectors.cache.enabled', false);
        $this->fake = ObserverFacade::fake();

        Event::dispatch(new CacheHit('redis', 'user:1', 'valor'));

        $this->fake->assertNothingRecorded(EventType::Cache);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function queryEvent(string $sql, array $bindings, float $time): QueryExecuted
    {
        return new QueryExecuted($sql, $bindings, $time, DB::connection());
    }

    private function fakeJob(): object
    {
        return new class
        {
            public function resolveName(): string
            {
                return 'App\\Jobs\\EnviarEmail';
            }

            public function getQueue(): string
            {
                return 'emails';
            }

            public function getJobId(): string
            {
                return 'job-123';
            }

            public function attempts(): int
            {
                return 1;
            }

            public function getConnectionName(): string
            {
                return 'redis';
            }

            public function payload(): array
            {
                return ['uuid' => 'job-123', 'data' => []];
            }

            public function hasFailed(): bool
            {
                return false;
            }

            public function isReleased(): bool
            {
                return false;
            }

            public function isDeletedOrReleased(): bool
            {
                return false;
            }
        };
    }

    private function scheduledTask(): ScheduledTask
    {
        $mutex = $this->createMock(EventMutex::class);

        return new ScheduledTask($mutex, 'php artisan app:relatorio');
    }
}
