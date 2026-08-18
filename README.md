# Observer SDK

SDK Laravel da plataforma de observabilidade **Observer**.

Instrumenta a aplicação inteira — exceptions, logs, queries, requests, jobs,
tarefas agendadas, cache e chamadas HTTP externas — e entrega tudo como eventos
normalizados a um **transporte plugável**.

- **PHP 8.1+** · **Laravel 10, 11 e 12**
- Zero configuração: os collectors se penduram nos eventos nativos do framework
- Desacoplado do backend: grava em arquivo, memória ou envia ao Observer Server
  (API REST em Go) trocando uma variável de ambiente
- Uma falha do SDK nunca derruba a aplicação

---

## Instalação

```bash
composer require guilhermeviana-observer/sdk
php artisan vendor:publish --tag=observer-config
```

O provider é descoberto automaticamente. Não é preciso registrar middleware,
listener ou canal de log.

Para enviar ao Observer Server, cole o DSN do projeto — uma linha, e só:

```dotenv
OBSERVER_DSN=https://lsec_sua_chave@sua-api-observer.com/id-do-projeto
```

O DSN é exibido uma única vez, ao criar o projeto no painel. Com ele presente o
transporte `http` liga sozinho; não é preciso definir mais nada.

> Atualizando de uma versão anterior? Republique o config —
> `php artisan vendor:publish --tag=observer-config --force` — para receber o
> padrão de transporte sensível ao DSN.

É uma credencial de **escrita**: envia eventos, mas não lê nenhum. Ler exige
login de usuário.

## Configuração mínima

```dotenv
OBSERVER_ENABLED=true
OBSERVER_PROJECT=minha-api
OBSERVER_TRANSPORT=file          # null | memory | file | http
OBSERVER_FILE_PATH=/var/log/observer.ndjson
```

Variável declarada e vazia (`OBSERVER_DSN=`) conta como **não definida**: o
`.env` pode ser preparado com os nomes em branco enquanto o endpoint do
servidor não existe, sem ligar nada pela metade e sem aviso indevido.

### Quando o SDK não consegue enviar

Configuração que não funciona não fica em silêncio: DSN inválido, transporte
sem endpoint ou servidor recusando o lote viram **uma** linha no log da sua
aplicação (uma por motivo, por processo), com o prefixo `[observer]`. Essas
linhas nunca voltam como eventos cobrados. Para silenciá-las:

```dotenv
OBSERVER_LOG_INTERNAL=false
```

`OBSERVER_DEBUG=true` é outra coisa: acrescenta o diagnóstico interno do
próprio SDK, útil só em desenvolvimento.

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
| `http` | produção — envia ao Observer Server em lote, com gzip acima de 1 KB e retry com backoff. Usa `ext-curl` quando disponível, com fallback para os wrappers nativos |

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
já foi enviada ao cliente.

## Desenvolvimento

```bash
composer test      # PHPUnit
composer lint      # Pint (PSR-12)
composer analyse   # PHPStan
```

## Licença

MIT.
