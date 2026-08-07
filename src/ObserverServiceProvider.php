<?php

declare(strict_types=1);

namespace Observer;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Observer\Buffer\EventBuffer;
use Observer\Collectors\CacheCollector;
use Observer\Collectors\CommandCollector;
use Observer\Collectors\ExceptionCollector;
use Observer\Collectors\HttpClientCollector;
use Observer\Collectors\LogCollector;
use Observer\Collectors\QueryCollector;
use Observer\Collectors\QueueCollector;
use Observer\Collectors\RequestCollector;
use Observer\Collectors\ScheduleCollector;
use Observer\Contracts\ClientInterface;
use Observer\Contracts\Collector;
use Observer\Contracts\EventProcessor;
use Observer\Log\ObserverLogChannel;
use Observer\Middleware\CaptureRequest;
use Observer\Pipeline\EventPipeline;
use Observer\Pipeline\Processors\BeforeSendProcessor;
use Observer\Pipeline\Processors\ContextProcessor;
use Observer\Pipeline\Processors\EnabledProcessor;
use Observer\Pipeline\Processors\IgnoreProcessor;
use Observer\Pipeline\Processors\SamplingProcessor;
use Observer\Pipeline\Processors\ScrubbingProcessor;
use Observer\Serializers\JsonSerializer;
use Observer\Services\ExceptionFormatter;
use Observer\Services\Redactor;
use Observer\Services\ScopeManager;
use Observer\Support\Configuration;
use Observer\Support\InternalLogger;
use Observer\Transport\TransportManager;

/**
 * Ponto de integração com o Laravel.
 *
 * register() só amarra bindings — nenhum listener, nenhum I/O. Tudo é lazy:
 * uma aplicação com o SDK desligado paga apenas o custo de registrar closures
 * no container.
 *
 * boot() é onde os collectors assinam os eventos do framework, e só acontece
 * se o SDK estiver habilitado para o ambiente corrente.
 */
final class ObserverServiceProvider extends ServiceProvider
{
    /**
     * Ordem importa: EnabledProcessor primeiro (mais barato, descarta mais),
     * BeforeSendProcessor por último (o desenvolvedor vê o evento pronto).
     *
     * @var list<class-string>
     */
    private const PROCESSORS = [
        EnabledProcessor::class,
        IgnoreProcessor::class,
        SamplingProcessor::class,
        ContextProcessor::class,
        ScrubbingProcessor::class,
        BeforeSendProcessor::class,
    ];

    /** @var list<class-string<Collector>> */
    private const COLLECTORS = [
        ExceptionCollector::class,
        LogCollector::class,
        QueryCollector::class,
        RequestCollector::class,
        HttpClientCollector::class,
        QueueCollector::class,
        ScheduleCollector::class,
        CacheCollector::class,
        CommandCollector::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/observer.php', 'observer');

        $this->registerSupport();
        $this->registerPipeline();
        $this->registerTransport();
        $this->registerClient();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/observer.php' => $this->app->configPath('observer.php'),
        ], 'observer-config');

        $config = $this->app->make(Configuration::class);

        if (! $config->isEnabled()) {
            return;
        }

        $this->registerCollectors($config);
        $this->registerLogChannel();
        $this->registerMiddleware();
        $this->registerShutdownFlush($config);
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            ClientInterface::class,
            Observer::class,
            Configuration::class,
            TransportManager::class,
            EventBuffer::class,
            EventPipeline::class,
            ScopeManager::class,
        ];
    }

    // -----------------------------------------------------------------
    // Bindings
    // -----------------------------------------------------------------

    private function registerSupport(): void
    {
        $this->app->singleton(Configuration::class, function (): Configuration {
            /** @var array<string, mixed> $items */
            $items = $this->app->make('config')->get('observer', []);

            return Configuration::fromArray($items);
        });

        $this->app->singleton(InternalLogger::class, function (): InternalLogger {
            $config = $this->app->make(Configuration::class);

            return new InternalLogger(
                logger: $config->debug() ? $this->app->make('log') : null,
                debug: $config->debug(),
            );
        });

        $this->app->singleton(ScopeManager::class, function (): ScopeManager {
            return new ScopeManager(
                $this->app->make(Configuration::class)->int('limits.max_breadcrumbs', 50)
            );
        });

        $this->app->singleton(Redactor::class, function (): Redactor {
            $config = $this->app->make(Configuration::class);

            /** @var list<string> $keys */
            $keys = $config->array('scrubbing.keys');
            /** @var list<string> $headers */
            $headers = $config->array('scrubbing.headers');

            return new Redactor(
                keys: $keys,
                headers: $headers,
                mask: $config->string('scrubbing.mask', '[FILTERED]') ?? '[FILTERED]',
                enabled: $config->bool('scrubbing.enabled', true),
                maxStringLength: $config->int('limits.max_string_length', 4096),
                maxArrayItems: $config->int('limits.max_array_items', 100),
                maxDepth: $config->int('limits.max_depth', 8),
            );
        });

        $this->app->singleton(ExceptionFormatter::class, function (): ExceptionFormatter {
            $config = $this->app->make(Configuration::class);

            return new ExceptionFormatter(
                basePath: $this->app->basePath(),
                frameLimit: $config->int('collectors.exceptions.stack_trace_limit', 50),
                contextLines: $config->int('collectors.exceptions.code_context_lines', 5),
            );
        });

        $this->app->singleton(JsonSerializer::class, static fn (): JsonSerializer => new JsonSerializer);
    }

    private function registerPipeline(): void
    {
        $this->app->singleton(ScrubbingProcessor::class, function (): ScrubbingProcessor {
            $config = $this->app->make(Configuration::class);

            return new ScrubbingProcessor(
                redactor: $this->app->make(Redactor::class),
                sendDefaultPii: $config->sendDefaultPii(),
            );
        });

        $this->app->singleton(BeforeSendProcessor::class, function (): BeforeSendProcessor {
            $callback = $this->app->make(Configuration::class)->get('before_send');

            return new BeforeSendProcessor(is_callable($callback) ? $callback : null);
        });

        $this->app->singleton(EventPipeline::class, function (): EventPipeline {
            $processors = array_map(
                fn (string $processor) => $this->app->make($processor),
                self::PROCESSORS,
            );

            /** @var list<EventProcessor> $processors */
            return new EventPipeline($processors, $this->app->make(InternalLogger::class));
        });
    }

    private function registerTransport(): void
    {
        $this->app->singleton(TransportManager::class, function (): TransportManager {
            return new TransportManager(
                config: $this->app->make(Configuration::class),
                serializer: $this->app->make(JsonSerializer::class),
                logger: $this->app->make(InternalLogger::class),
            );
        });

        $this->app->singleton(EventBuffer::class, function (): EventBuffer {
            $config = $this->app->make(Configuration::class);

            return new EventBuffer(
                transport: $this->app->make(TransportManager::class)->driver(),
                maxSize: $config->int('buffer.size', 50),
                enabled: $config->bool('buffer.enabled', true),
                logger: $this->app->make(InternalLogger::class),
            );
        });
    }

    private function registerClient(): void
    {
        $this->app->singleton(Observer::class, function (): Observer {
            return new Observer(
                config: $this->app->make(Configuration::class),
                pipeline: $this->app->make(EventPipeline::class),
                buffer: $this->app->make(EventBuffer::class),
                scope: $this->app->make(ScopeManager::class),
                formatter: $this->app->make(ExceptionFormatter::class),
                logger: $this->app->make(InternalLogger::class),
            );
        });

        $this->app->alias(Observer::class, ClientInterface::class);
        $this->app->alias(Observer::class, 'observer');
    }

    // -----------------------------------------------------------------
    // Integração
    // -----------------------------------------------------------------

    private function registerCollectors(Configuration $config): void
    {
        $events = $this->app->make(Dispatcher::class);
        $logger = $this->app->make(InternalLogger::class);

        foreach (self::COLLECTORS as $class) {
            // O container é passado no lugar do cliente: os listeners são
            // registrados uma vez só, mas resolvem cliente e config a cada
            // evento, respeitando rebinds posteriores.
            $collector = new $class($this->app, $events, $logger);

            if (! $config->collectorEnabled($collector->name())) {
                continue;
            }

            // O ExceptionCollector precisa do handler do Laravel para se
            // pendurar no reportable(); é a única dependência extra.
            if ($collector instanceof ExceptionCollector) {
                $collector->withHandler(
                    $this->app->bound(ExceptionHandler::class)
                        ? $this->app->make(ExceptionHandler::class)
                        : null
                );
            }

            $logger->safely(static fn () => $collector->register(), 'collector:'.$collector->name());
        }
    }

    private function registerLogChannel(): void
    {
        if (! $this->app->bound('log')) {
            return;
        }

        Log::extend('observer', function ($app, array $config) {
            return (new ObserverLogChannel($app->make(ClientInterface::class)))($config);
        });
    }

    /**
     * Empilha o middleware globalmente para que o flush no terminate valha
     * para toda a aplicação, sem exigir edição do bootstrap/app.php.
     */
    private function registerMiddleware(): void
    {
        if ($this->app->runningInConsole() || ! $this->app->bound(Kernel::class)) {
            return;
        }

        $kernel = $this->app->make(Kernel::class);

        if (method_exists($kernel, 'pushMiddleware')) {
            $kernel->pushMiddleware(CaptureRequest::class);
        }
    }

    /**
     * Rede de segurança dupla: terminating() cobre o fim normal do ciclo
     * (HTTP e console) e o shutdown function cobre morte abrupta — exit(),
     * erro fatal, memória esgotada.
     */
    private function registerShutdownFlush(Configuration $config): void
    {
        if (! $config->bool('buffer.flush_on_shutdown', true)) {
            return;
        }

        $this->app->terminating(function (): void {
            $this->app->make(ClientInterface::class)->flush();
        });

        register_shutdown_function(function (): void {
            if (! $this->app->resolved(Observer::class)) {
                return;
            }

            $this->app->make(ClientInterface::class)->flush();
        });
    }
}
