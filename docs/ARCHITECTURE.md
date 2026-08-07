# Observer SDK — Documento de Arquitetura

> Versão 1.0 — SDK Laravel da plataforma Observer
> Alvo: PHP 8.3+ · Laravel 11 / 12 · PSR-3, PSR-4, PSR-12

---

## 1. Visão geral

O Observer SDK é um **pacote Composer** que instrumenta uma aplicação Laravel e
transforma tudo o que acontece dentro dela (exceptions, logs, queries, requests,
jobs, cache, tasks agendadas, chamadas HTTP externas) em **eventos normalizados**,
entregues a um **transporte plugável**.

Princípio central:

> O SDK **não sabe** para onde os dados vão. Ele apenas produz eventos e os entrega
> a uma abstração `Transport`.

Isso permite que o `HttpTransport` (que falará com a API Go do Observer Server)
seja adicionado no futuro **sem alterar uma única linha** de collector, pipeline
ou API pública.

### 1.1 Camadas (Clean Architecture)

```
┌───────────────────────────────────────────────────────────────┐
│  CAMADA DE INTEGRAÇÃO (Laravel)                               │
│  ServiceProvider · Facade · Middleware · Monolog Handler      │
│  Collectors (ouvem os eventos nativos do framework)           │
└───────────────┬───────────────────────────────────────────────┘
                │ produz DTOs
┌───────────────▼───────────────────────────────────────────────┐
│  NÚCLEO (framework-agnostic)                                  │
│  Observer (Client) · EventPipeline · EventBuffer · Scope      │
│  DTOs imutáveis · Enums · Contracts                           │
└───────────────┬───────────────────────────────────────────────┘
                │ Event[] serializados
┌───────────────▼───────────────────────────────────────────────┐
│  CAMADA DE SAÍDA                                              │
│  Serializer (JSON) · Transport (Null/Memory/File/[Http])      │
└───────────────────────────────────────────────────────────────┘
```

A dependência aponta sempre para dentro: a integração conhece o núcleo, o núcleo
conhece apenas **contracts**, e a camada de saída implementa esses contracts.
O núcleo não faz `use Illuminate\...` em lugar nenhum — isso mantém o SDK testável
sem bootar o framework e permite reaproveitá-lo em outros contextos (Symfony, CLI puro).

---

## 2. Organização de diretórios

| Diretório | Responsabilidade |
|---|---|
| `src/Contracts/` | Interfaces do SDK. Único ponto de acoplamento entre camadas: `Transport`, `EventProcessor`, `Collector`, `Serializer`, `Sampler`, `ClientInterface`, `ContextProvider`. |
| `src/DTO/` | Objetos de transferência **imutáveis** (`readonly`). `Event` é o envelope; `DTO/Payloads/` contém os payloads tipados de cada domínio (exception, query, request…). Sem lógica de negócio. |
| `src/Enums/` | `EventType` e `Severity` — vocabulário fechado do protocolo de eventos. |
| `src/Collectors/` | Adaptadores que escutam o Laravel e traduzem sinais do framework em `Event`. Um collector por domínio. São a **única** parte que conhece o framework a fundo. |
| `src/Pipeline/` | Cadeia de processamento aplicada a todo evento antes do buffer: amostragem, enriquecimento de contexto, mascaramento, truncamento, filtros e `before_send`. |
| `src/Transport/` | Implementações de saída (`NullTransport`, `MemoryTransport`, `FileTransport`, futuro `HttpTransport`) e o `TransportManager` que as resolve por configuração. |
| `src/Serializers/` | Conversão `Event → string`. `JsonSerializer` é o padrão; isolar aqui permite trocar por MessagePack/Protobuf sem tocar no transporte. |
| `src/Buffer/` | Acúmulo em memória dos eventos e política de flush (por tamanho, por tempo, no shutdown). |
| `src/Services/` | Serviços de aplicação sem estado de framework: `ScopeManager` (contexto corrente: user, tags, breadcrumbs), `ExceptionFormatter`, `Redactor`. |
| `src/Support/` | Utilitários puros: `Uuid`, `Clock`, `Arr`, `Sanitizer`, `Str`. Sem dependências. |
| `src/Log/` | Integração PSR-3/Monolog: `ObserverHandler` (Monolog) e `ObserverLogChannel` (driver `logging.php`). |
| `src/Middleware/` | `CaptureRequest` — mede a request e garante flush em `terminate()`. |
| `src/Exceptions/` | Exceções próprias do SDK. Nunca vazam para a aplicação: são capturadas e logadas internamente. |
| `src/Facades/` | `Observer` facade → resolve `ClientInterface` do container. |
| `src/Helpers/` | `functions.php` com o helper global `observer()`. |
| `config/` | `observer.php` — configuração completa e documentada. |
| `tests/` | `Unit/` (núcleo, sem framework) e `Feature/` (com Orchestra Testbench). |
| `docs/` | Esta documentação + guias de uso. |

---

## 3. Fluxo interno e ciclo de vida do evento

```
  (1) SINAL                (2) COLETA              (3) NÚCLEO
  ─────────                ──────────              ───────────
  Throwable        ┐
  Log record       │       Collector          Observer::capture*()
  DB::listen       ├──────▶ traduz p/  ──────▶ cria Event (id, ts,
  RequestHandled   │        payload           type, level, payload)
  JobProcessed     │        tipado
  CacheHit …       ┘
                                                       │
                     (4) PIPELINE                      ▼
   ┌──────────────────────────────────────────────────────────────┐
   │ 1. EnabledProcessor   → SDK ligado? ambiente e collector ok?  │
   │ 2. IgnoreProcessor    → exception/rota/chave na blacklist?    │
   │ 3. SamplingProcessor  → sorteio por sample_rate do tipo       │
   │ 4. ContextProcessor   → runtime, servidor, usuário, trace     │
   │ 5. ScrubbingProcessor → mascara segredos, corta PII, trunca   │
   │ 6. BeforeSendProcessor→ callback do desenvolvedor             │
   └──────────────────────────────────────────────────────────────┘
              │ retorna Event   │ retorna null
              ▼                 ▼
      (5) EventBuffer        DESCARTADO (com contador de drop)
              │
              │ flush por: limite atingido · flush() manual ·
              │            terminate() da request · shutdown handler
              ▼
      (6) Serializer  →  (7) Transport::sendBatch()
```

**Ciclo de vida em uma request HTTP típica**

1. `ObserverServiceProvider::register()` — binda `ClientInterface` como singleton (lazy).
2. `boot()` — registra os collectors habilitados (cada um faz `Event::listen(...)`).
3. Middleware `CaptureRequest` marca o `t0`.
4. Durante a request, collectors alimentam o buffer.
5. `terminate()` do middleware fecha o `RequestEvent` e chama `Observer::flush()`.
6. Se o processo morrer antes (fatal error), o `shutdown handler` registrado no boot
   faz o flush de emergência.

---

## 4. Classes principais

### 4.1 Contracts

```php
interface Transport {
    public function send(Event $event): void;
    public function sendBatch(array $events): void;   // Event[]
    public function flush(): bool;                    // garante entrega do pendente
    public function close(): void;
}

interface EventProcessor {
    public function process(Event $event): ?Event;    // null = descartar
}

interface Collector {
    public function name(): string;
    public function register(): void;                 // assina os hooks do Laravel
}

interface Serializer {
    public function serialize(Event $event): string;
    public function serializeBatch(array $events): string;
}

interface ClientInterface {                            // a API pública
    public function capture(Throwable $e, array $context = []): ?string;
    public function log(string|Severity $level, string $message, array $context = []): ?string;
    public function event(string $name, array $payload = [], array $context = []): ?string;
    public function metric(string $name, float $value, array $tags = []): ?string;
    public function record(Event $event): ?string;     // porta de entrada dos collectors
    public function flush(): bool;
}
```

### 4.2 Núcleo

| Classe | Papel |
|---|---|
| `Observer\Observer` | Implementação de `ClientInterface`. Fábrica de eventos + orquestrador (pipeline → buffer). Não conhece transporte diretamente. |
| `Observer\Pipeline\EventPipeline` | Executa os `EventProcessor` em ordem; curto-circuita no primeiro `null`. |
| `Observer\Buffer\EventBuffer` | Fila em memória com `max_size`; ao estourar, drena para o transporte. |
| `Observer\Services\ScopeManager` | Escopo corrente: usuário, tags, extra, breadcrumbs, `trace_id`. Injetado pelo `ContextProcessor`. |
| `Observer\Transport\TransportManager` | Resolve o driver configurado; suporta drivers customizados via `extend()`. |
| `Observer\Support\Configuration` | Objeto tipado sobre o array de config (evita `config()` espalhado e strings mágicas). Snapshot imutável tirado no boot — ler o repositório do Laravel a cada query custaria caro. |
| `Observer\Services\Redactor` | Mascaramento recursivo + limites de tamanho/profundidade, em uma única passada. |
| `Observer\Testing\ObserverFake` | Dublê de teste: troca o transporte por `MemoryTransport` e expõe os asserts. |

### 4.3 DTOs

`Event` é um **envelope final e imutável**:

```php
final readonly class Event {
    public function __construct(
        public string    $id,          // UUID v4
        public EventType $type,
        public Severity  $level,
        public string    $message,
        public float     $timestamp,   // microtime(true)
        public array     $payload,     // específico do tipo
        public array     $context = [],// runtime, user, request, trace
        public array     $tags = [],
    ) {}

    public function withPayload(array $p): self;   // clone
    public function withContext(array $c): self;
    public function withTags(array $t): self;
    public function toArray(): array;
}
```

Os payloads tipados vivem em `DTO/Payloads/` (`ExceptionPayload`, `QueryPayload`,
`RequestPayload`, `HttpClientPayload`, `QueuePayload`, `CachePayload`,
`ScheduledTaskPayload`, `LogPayload`, `MetricPayload`) e implementam
`Contracts\Payload::toArray()`. Vantagem: o collector monta um objeto validado,
não um array solto — erros de campo aparecem em tempo de análise estática.

`Enums\EventType`: `exception`, `log`, `query`, `request`, `http_client`, `queue`,
`schedule`, `cache`, `metric`, `custom`.
`Enums\Severity`: os 8 níveis PSR-3 (`debug` → `emergency`), com `fromPsrLevel()`
e `isAtLeast()` para filtro por severidade mínima.

---

## 5. Collectors — quais eventos nativos do Laravel usar

Objetivo: **zero configuração manual**. Tudo é assinado no `boot()` do provider.

| Collector | Hook do Laravel | Dados capturados |
|---|---|---|
| `ExceptionCollector` | `$handler->reportable()` + `set_exception_handler` + `register_shutdown_function` (fatais) | classe, mensagem, código, arquivo/linha, stacktrace com trechos de código, exceptions encadeadas, fingerprint |
| `LogCollector` | `MessageLogged` — funciona com qualquer canal, sem tocar no `logging.php`. O `Log\ObserverHandler` (Monolog) fica como caminho alternativo, para quem quiser instrumentar um canal só | nível, mensagem, contexto, canal |
| `QueryCollector` | `DB::listen(fn (QueryExecuted $q) => …)` | SQL, bindings, tempo (ms), conexão, flag de slow query |
| `RequestCollector` | `RouteMatched`, `RequestHandled` (Laravel 11+) + middleware `CaptureRequest` | método, URL, rota, status, duração, IP, user agent, headers (mascarados), memória de pico |
| `HttpClientCollector` | `ResponseReceived`, `ConnectionFailed`, `RequestSending` (`Illuminate\Http\Client\Events\*`) | URL, método, status, duração, tamanho do corpo |
| `QueueCollector` | `JobQueued`, `JobProcessing`, `JobProcessed`, `JobFailed`, `JobExceptionOccurred` | nome do job, fila, conexão, tentativa, duração, exception |
| `ScheduleCollector` | `ScheduledTaskStarting`, `ScheduledTaskFinished`, `ScheduledTaskFailed`, `ScheduledTaskSkipped` | comando, expressão cron, duração, saída, status |
| `CacheCollector` | `CacheHit`, `CacheMissed`, `KeyWritten`, `KeyForgotten` | chave, store, tags, TTL |
| `CommandCollector` (extra) | `CommandStarting`, `CommandFinished` | comando artisan, exit code, duração |

Todos os collectors:
- são **opt-out por configuração** (`observer.collectors.*.enabled`);
- fazem `try/catch` em torno da coleta — **um erro no SDK nunca quebra a aplicação**;
- respeitam um *guard* de reentrância (uma query disparada pelo próprio SDK não é coletada).

---

## 6. Transport Layer

```php
$transport = $manager->driver();   // resolvido de observer.transport.driver
$transport->sendBatch($events);
```

| Driver | Uso | Comportamento |
|---|---|---|
| `NullTransport` | produção com SDK desligado, testes de terceiros | descarta tudo; custo O(1) |
| `MemoryTransport` | testes automatizados | guarda em array, expõe `events()`, `flushed()`, `clear()` — permite asserts |
| `FileTransport` | desenvolvimento local, ambientes sem rede | grava **JSON Lines** (`ndjson`), append com `LOCK_EX`, rotação por tamanho e retenção por dias |
| `HttpTransport` *(futuro)* | produção com Observer Server (Go) | `POST /api/v1/events` em lote, `Authorization: Bearer <api_key>`, gzip, retry com backoff exponencial + jitter, timeout curto, circuit breaker |

O `TransportManager` segue o padrão *Manager* do Laravel (`createNullDriver`,
`createFileDriver`, …) e aceita `extend('meu-driver', $closure)` — extensibilidade
sem herança.

**Preparação para o HttpTransport:** a interface já é *batch-first*, o `Serializer`
já produz o corpo da requisição, o buffer já agrupa, e a config já reserva a seção
`transport.http` (endpoint, api_key, timeout, retries, compressão). Quando o servidor
existir, basta implementar a classe e trocar `OBSERVER_TRANSPORT=http`.

---

## 7. Performance — estratégias e trade-offs

| Estratégia | Vantagem | Desvantagem | Decisão |
|---|---|---|---|
| **Buffer em memória** | 1 I/O por request em vez de N | eventos perdidos em `kill -9`; consome RAM | **Adotado**, com `max_size` e shutdown handler |
| **Batch** | menos syscalls/round-trips; compressão melhor | latência de entrega; payload grande | **Adotado** (padrão 50 eventos) |
| **Fire and forget** | impacto ~0 no tempo de resposta | sem garantia de entrega | **Adotado** no HTTP futuro (`flush_on_terminate`) |
| **Retry + backoff** | resiliência a falha transitória | pode segurar o processo | Só no `HttpTransport`, com teto de tentativas e timeout duro |
| **Compressão gzip** | menos banda (JSON comprime ~80%) | CPU | Opcional, ligada acima de N KB |
| **Sampling** | corta volume linearmente | perde fidelidade estatística | Adotado, taxa por tipo de evento |
| **Serialização lazy** | não serializa o que será descartado | código mais complexo | Adotado: serializa só no flush, depois do pipeline |
| **Envio via fila (`dispatch`)** | tira I/O do request | exige worker; recursivo se a fila for instrumentada | Previsto como `queue` no HttpTransport, com guard anti-recursão |
| **Shutdown handler** | pega fatal error e `exit()` | roda mesmo em caminhos felizes | Adotado via `register_shutdown_function` + `app()->terminating()` |

Limites rígidos de segurança: truncamento de strings (`max_string_length`),
profundidade de array (`max_depth`), tamanho do lote e do buffer. O SDK **nunca**
deve conseguir consumir memória sem teto.

---

## 8. Configuração

`config/observer.php` cobre: chaveamento geral, identidade (projeto, ambiente,
release, server name), transporte e opções por driver, buffer, amostragem,
collectors com opções próprias (ex.: `slow_query_threshold`), mascaramento de dados
sensíveis, limites, listas de ignore e o hook `before_send`.

Variáveis `.env` (todas com default seguro):

```
OBSERVER_ENABLED, OBSERVER_API_KEY, OBSERVER_ENDPOINT, OBSERVER_PROJECT,
OBSERVER_ENVIRONMENT, OBSERVER_RELEASE, OBSERVER_SERVER_NAME,
OBSERVER_TRANSPORT, OBSERVER_FILE_PATH, OBSERVER_FILE_MAX_SIZE,
OBSERVER_BUFFER_ENABLED, OBSERVER_BUFFER_SIZE, OBSERVER_FLUSH_ON_SHUTDOWN,
OBSERVER_SAMPLE_RATE, OBSERVER_TRACES_SAMPLE_RATE,
OBSERVER_CAPTURE_EXCEPTIONS, OBSERVER_CAPTURE_LOGS, OBSERVER_LOG_LEVEL,
OBSERVER_CAPTURE_QUERIES, OBSERVER_QUERY_SLOW_MS, OBSERVER_CAPTURE_BINDINGS,
OBSERVER_CAPTURE_REQUESTS, OBSERVER_CAPTURE_HTTP_CLIENT, OBSERVER_CAPTURE_QUEUE,
OBSERVER_CAPTURE_SCHEDULE, OBSERVER_CAPTURE_CACHE, OBSERVER_CAPTURE_COMMANDS,
OBSERVER_SEND_DEFAULT_PII, OBSERVER_MAX_BREADCRUMBS, OBSERVER_DEBUG
```

---

## 9. API pública

```php
Observer::capture($exception, ['order_id' => 42]);
Observer::log('error', 'Pagamento recusado', ['gateway' => 'stripe']);
Observer::event('checkout.completed', ['total' => 199.90]);
Observer::metric('queue.depth', 128, ['queue' => 'default']);

Observer::withUser(['id' => $user->id, 'email' => $user->email]);
Observer::withTags(['tenant' => 'acme']);
Observer::breadcrumb('cart', 'Item adicionado', ['sku' => 'X1']);

Observer::flush();
```

A facade resolve `ClientInterface`; injeção de dependência é sempre possível
(`public function __construct(private ClientInterface $observer)`), o que mantém o
código do usuário testável.

---

## 10. Testes

- **Unit** (sem framework): DTOs, enums, pipeline, processors, buffer, serializer,
  `FileTransport` sobre diretório temporário, redator de dados sensíveis.
- **Feature** (Orchestra Testbench 9/10): provider, facade, publicação da config,
  cada collector contra eventos reais do Laravel, middleware, handler Monolog.
- **MemoryTransport** é o dublê oficial: `Observer::fake()` troca o transporte,
  desliga o buffer e habilita os asserts (`assertCaptured`, `assertRecorded`,
  `assertNothingRecorded`, `assertMetric`…).
- Ferramentas: PHPUnit 11, PHPStan nível 6, Laravel Pint (preset Laravel),
  matriz CI PHP 8.3/8.4 × Laravel 11/12.

**Estado atual:** 68 testes / 185 asserts, Pint e PHPStan limpos.

> Nota de ambiente: o PHP 8.5 desta máquina não tem `ext-dom`, exigida pelo
> PHPUnit, então a suíte foi executada localmente no PHP 8.2 (`composer update
> --ignore-platform-req=php`). O código é compatível de 8.2 a 8.5; o alvo
> declarado no `composer.json` continua sendo 8.3+, validado no CI.

---

## 11. Compatibilidade Laravel 11 / 12

- `composer.json`: `illuminate/support: ^11.0|^12.0`, `illuminate/contracts` idem,
  `monolog/monolog: ^3.0`.
- Auto-discovery via `extra.laravel.providers` + `aliases`.
- Sem uso de APIs removidas; os eventos usados (`QueryExecuted`, `JobProcessed`,
  `ScheduledTaskFinished`, `CacheHit`, `Http\Client\Events\*`, `RequestHandled`)
  existem e são estáveis em ambas as versões.
- Onde houver divergência, um `Support\LaravelVersion` isola o `if` — nunca
  espalhado pelos collectors.
- Testbench: `^9.0` (L11) e `^10.0` (L12) na matriz de CI.

---

## 12. Roadmap de implementação

| Fase | Entrega | Status |
|---|---|---|
| 1 | Arquitetura (este documento) | ✅ |
| 2 | Estrutura do projeto + composer.json | ✅ |
| 3 | Sistema de configuração | ✅ |
| 4 | Transport Layer (Null/Memory/File + Manager) | ✅ |
| 5 | Collectors | ✅ |
| 6 | Pipeline + Processors | ✅ |
| 7 | Facade + API pública | ✅ |
| 8 | Integração automática com Laravel | ✅ |
| 9 | Testes | ✅ |
| 10 | Publicação no Packagist | ⏳ |
| 11 | `HttpTransport` + Observer Server (Go) | ⏳ |
