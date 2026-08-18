<?php

declare(strict_types=1);

namespace Observer\Transport;

use Closure;
use Observer\Contracts\Transport;
use Observer\Exceptions\TransportException;
use Observer\Serializers\JsonSerializer;
use Observer\Support\Configuration;
use Observer\Support\Dsn;
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
        $this->warnAboutIgnoredDsn($name ?? $this->defaultDriver());

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

    /**
     * Avisa quando existe DSN configurado mas o transporte escolhido não envia.
     *
     * É a combinação que produz o pior tipo de bug: o usuário cola o DSN,
     * conclui que integrou, e nada aparece no painel — sem erro nenhum. A
     * configuração da aplicação continua vencendo (não trocamos o driver por
     * conta própria), mas o silêncio vira uma linha no log.
     *
     * Acontece tipicamente ao ATUALIZAR o SDK sem republicar o config: o
     * arquivo antigo tem `driver => env('OBSERVER_TRANSPORT', 'file')`, sem
     * conhecer o DSN.
     */
    private function warnAboutIgnoredDsn(string $driver): void
    {
        if ($driver === 'http' || $this->warnedAboutDsn) {
            return;
        }

        // string() já trata `OBSERVER_DSN=` vazio como não definido: avisar
        // sobre um DSN que o usuário não chegou a preencher seria ruído puro
        // em toda aplicação que ainda não integrou.
        if ($this->dsn() === null) {
            return;
        }

        $this->warnedAboutDsn = true;

        $this->logger?->warning(
            "OBSERVER_DSN está definido, mas o transporte em uso é '{$driver}': nenhum evento será enviado. ".
            'Defina OBSERVER_TRANSPORT=http ou republique o config com '.
            '`php artisan vendor:publish --tag=observer-config --force`.'
        );
    }

    private bool $warnedAboutDsn = false;

    /**
     * DSN configurado, ou null quando ausente/vazio.
     */
    private function dsn(): ?string
    {
        return $this->config->string('transport.http.dsn');
    }

    private function createHttpDriver(): Transport
    {
        $raw = $this->dsn();

        // O DSN, quando presente, é a fonte da verdade: ele carrega endpoint e
        // chave numa string só. Os valores separados continuam funcionando para
        // quem prefere configurá-los assim.
        $dsn = Dsn::parse($raw);
        $dsnInvalido = $dsn === null && $raw !== null;

        if ($dsnInvalido) {
            $this->logger?->error(
                'OBSERVER_DSN inválido: o formato é https://<chave>@<host>/<id-do-projeto>.'
            );
        }

        $endpoint = $dsn !== null
            ? $dsn->endpoint
            : $this->config->string('transport.http.endpoint');

        // Sem endpoint não existe envio possível, e insistir custa caro: cada
        // flush montaria uma URL quebrada, esperaria o timeout e ainda dormiria
        // o backoff entre as tentativas — dentro do request da aplicação. O
        // transporte nulo descarta na hora e o motivo fica no log, uma vez.
        if ($endpoint === null) {
            // Com DSN inválido o motivo já foi registrado acima; repetir só
            // trocaria a causa real por uma consequência dela.
            if (! $dsnInvalido) {
                $this->logger?->error(
                    'Transporte http selecionado sem endpoint: defina OBSERVER_DSN ou OBSERVER_ENDPOINT. '.
                    'Nenhum evento será enviado.'
                );
            }

            return $this->createNullDriver();
        }

        return new HttpTransport(
            endpoint: $endpoint,
            apiKey: $dsn !== null ? $dsn->key : $this->config->string('transport.http.api_key'),
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
