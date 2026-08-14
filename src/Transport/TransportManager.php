<?php

declare(strict_types=1);

namespace Observer\Transport;

use Closure;
use Observer\Contracts\Transport;
use Observer\Exceptions\TransportException;
use Observer\Serializers\JsonSerializer;
use Observer\Support\Configuration;
use Observer\Support\InternalLogger;

/**
 * Fábrica de transportes, no padrão Manager do Laravel.
 *
 * Resolve o driver configurado, mantém instâncias resolvidas e aceita drivers
 * customizados via extend() — extensibilidade sem herança.
 */
final class TransportManager
{
    /** @var array<string, Transport> */
    private array $resolved = [];

    /** @var array<string, Closure(Configuration): Transport> */
    private array $customCreators = [];

    public function __construct(
        private readonly Configuration $config,
        private readonly JsonSerializer $serializer = new JsonSerializer,
        private readonly ?InternalLogger $logger = null,
    ) {}

    public function driver(?string $name = null): Transport
    {
        $name ??= $this->defaultDriver();

        return $this->resolved[$name] ??= $this->resolve($name);
    }

    public function defaultDriver(): string
    {
        return $this->config->string('transport.driver', 'null') ?? 'null';
    }

    /**
     * @param Closure(Configuration): Transport $creator
     */
    public function extend(string $name, Closure $creator): self
    {
        $this->customCreators[$name] = $creator;

        return $this;
    }

    /**
     * Substitui o transporte resolvido — usado por Observer::fake().
     */
    public function set(string $name, Transport $transport): self
    {
        $this->resolved[$name] = $transport;

        return $this;
    }

    public function forget(?string $name = null): void
    {
        $name ??= $this->defaultDriver();

        unset($this->resolved[$name]);
    }

    private function resolve(string $name): Transport
    {
        if (isset($this->customCreators[$name])) {
            return ($this->customCreators[$name])($this->config);
        }

        return match ($name) {
            'null', 'none', '' => $this->createNullDriver(),
            'memory', 'array' => $this->createMemoryDriver(),
            'file', 'log' => $this->createFileDriver(),
            'http' => $this->createHttpDriver(),
            default => throw TransportException::unsupportedDriver($name),
        };
    }

    private function createNullDriver(): NullTransport
    {
        return new NullTransport;
    }

    private function createMemoryDriver(): MemoryTransport
    {
        return new MemoryTransport;
    }

    private function createFileDriver(): FileTransport
    {
        return new FileTransport(
            path: $this->config->string('transport.file.path', 'observer.ndjson') ?? 'observer.ndjson',
            serializer: $this->serializer,
            maxSize: $this->config->int('transport.file.max_size', 10 * 1024 * 1024),
            maxFiles: $this->config->int('transport.file.max_files', 5),
            permission: $this->config->int('transport.file.permission', 0644),
            logger: $this->logger,
        );
    }

    private function createHttpDriver(): HttpTransport
    {
        return new HttpTransport(
            endpoint: $this->config->string('transport.http.endpoint', '') ?? '',
            apiKey: $this->config->string('transport.http.api_key'),
            serializer: $this->serializer,
            timeout: $this->config->float('transport.http.timeout', 2.0),
            connectTimeout: $this->config->float('transport.http.connect_timeout', 1.0),
            retries: $this->config->int('transport.http.retries', 2),
            retryDelayMs: $this->config->int('transport.http.retry_delay_ms', 200),
            compress: $this->config->bool('transport.http.compress', true),
            compressThreshold: $this->config->int('transport.http.compress_threshold', 1024),
            logger: $this->logger,
            totalBudgetMs: $this->config->int('transport.http.total_budget_ms', 3000),
            maxBatch: $this->config->int('buffer.size', 50),
        );
    }
}
