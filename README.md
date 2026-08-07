# Observer SDK

SDK Laravel da plataforma de observabilidade **Observer**.

Instrumenta a aplicação inteira — exceptions, logs, queries, requests, jobs,
tarefas agendadas, cache e chamadas HTTP externas — e entrega tudo como eventos
normalizados a um **transporte plugável**.

- **PHP 8.3+** · **Laravel 11 e 12**
- Zero configuração: os collectors se penduram nos eventos nativos do framework
- Desacoplado do backend: hoje grava em arquivo/memória, amanhã envia ao
  Observer Server (API REST em Go) trocando uma variável de ambiente
- Uma falha do SDK nunca derruba a aplicação

📖 **Entenda o projeto inteiro:** [`docs/EXPLICACAO-SDK.md`](docs/EXPLICACAO-SDK.md)
— o que cada peça faz e por quê.
🏛 **Desenho da arquitetura:** [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)

---

## Instalação

```bash
composer require observer/sdk
php artisan vendor:publish --tag=observer-config
```

O provider é descoberto automaticamente. Não é preciso registrar middleware,
listener ou canal de log.

## Configuração mínima

```dotenv
OBSERVER_ENABLED=true
OBSERVER_PROJECT=minha-api
OBSERVER_TRANSPORT=file          # null | memory | file | http (futuro)
OBSERVER_FILE_PATH=/var/log/observer.ndjson
```

## API pública

```php
use Observer\Facades\Observer;

Observer::capture($exception, ['order_id' => 42]);
Observer::log('error', 'Pagamento recusado', ['gateway' => 'stripe']);
Observer::event('checkout.completed', ['total' => 199.90]);
Observer::metric('queue.depth', 128, ['queue' => 'default']);

Observer::withUser(['id' => $user->id]);
Observer::withTags(['tenant' => 'acme']);
Observer::breadcrumb('cart', 'Item adicionado', ['sku' => 'X1']);

Observer::flush();
```

Injeção de dependência (preferível — mantém seu código testável):

```php
use Observer\Contracts\ClientInterface;

public function __construct(private ClientInterface $observer) {}
```

Ou o helper global: `observer()->capture($e);`

## O que é capturado automaticamente

| Domínio | Evento do Laravel | Dados |
|---|---|---|
| Exceptions | `reportable()`, `set_exception_handler`, shutdown | classe, mensagem, stacktrace com trecho de código, encadeamento, fingerprint |
| Logs | `MessageLogged` | nível, mensagem, contexto, canal |
| Queries | `QueryExecuted` | SQL, tempo, bindings, conexão, origem no seu código, flag de lentidão |
| Requests | `RequestHandled` | método, URL, rota, status, duração, headers mascarados, pico de memória |
| HTTP Client | `RequestSending` / `ResponseReceived` / `ConnectionFailed` | método, host, status, duração, tamanho |
| Queue | `JobProcessing` / `JobProcessed` / `JobFailed` / `JobQueued` | job, fila, tentativas, duração, exception |
| Scheduler | `ScheduledTask*` | comando, cron, duração, exit code, saída |
| Cache | `CacheHit` / `CacheMissed` / `KeyWritten` / `KeyForgotten` | operação, chave, store, TTL |
| Commands | `CommandStarting` / `CommandFinished` | comando, exit code, duração *(off por padrão)* |

Cada um pode ser desligado em `config/observer.php`.

## Transportes

| Driver | Uso |
|---|---|
| `null` | descarta tudo, custo O(1) |
| `memory` | testes — acumula em memória com asserts prontos |
| `file` | desenvolvimento local — JSON Lines com rotação por tamanho |
| `http` | **futuro** — envia ao Observer Server; contrato já definido |

Driver próprio, sem herdar de nada:

```php
app(\Observer\Transport\TransportManager::class)
    ->extend('kafka', fn ($config) => new KafkaTransport($config));
```

## Testes na sua aplicação

```php
use Observer\Facades\Observer;

$fake = Observer::fake();

$this->post('/pedidos', [...]);

$fake->assertCaptured(PaymentFailedException::class)
     ->assertEvent('checkout.completed')
     ->assertMetric('pedidos.total', 1.0);
```

## Privacidade

Mascaramento recursivo de senhas, tokens, cartões, CPF/CNPJ e headers de
autenticação — aplicado antes de qualquer serialização. PII (IP, user agent,
e-mail, corpo da request) fica **de fora por padrão**; ligue com
`OBSERVER_SEND_DEFAULT_PII=true` se o seu caso de uso permitir.

## Desempenho

Buffer em memória com entrega em lote, amostragem por tipo de evento, limites
rígidos de tamanho/profundidade e flush no `terminate()` — depois que a resposta
já foi enviada ao cliente. As decisões e seus trade-offs estão detalhados em
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Desenvolvimento

```bash
composer test      # PHPUnit
composer lint      # Pint (PSR-12)
composer analyse   # PHPStan
```

## Licença

MIT.
