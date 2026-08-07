# Explicação do Observer SDK

> Documento de leitura. Explica **o que o projeto faz hoje**, como cada peça
> funciona por dentro e por que foi feita assim.
> Para o desenho formal da arquitetura, veja [`ARCHITECTURE.md`](ARCHITECTURE.md).

---

## 1. Em uma frase

O Observer SDK é um pacote Composer que, ao ser instalado em uma aplicação
Laravel, **passa a observar tudo o que acontece dentro dela** — erros, logs,
queries, requisições, jobs, tarefas agendadas, cache e chamadas HTTP externas —
transformando cada acontecimento em um **evento padronizado** e entregando esses
eventos a um **destino trocável**.

O desenvolvedor não escreve nenhuma linha de instrumentação. Instala, configura
duas variáveis e o SDK começa a trabalhar.

---

## 2. O problema que ele resolve

Quando algo dá errado em produção, as perguntas são sempre as mesmas:

- Qual erro aconteceu, em que linha, e quantas vezes?
- Que query estava lenta naquele momento?
- O job da fila rodou? Falhou? Quantas tentativas?
- A tarefa do cron rodou mesmo, ou foi pulada silenciosamente?
- Aquela API externa respondeu, ou deu timeout?

Responder isso normalmente exige espalhar `Log::info()` pelo código, o que suja
a aplicação e ainda produz texto solto, difícil de agregar.

O Observer SDK responde essas perguntas **sem tocar no código da aplicação**,
porque se pendura nos eventos que o próprio Laravel já dispara internamente.

---

## 3. A ideia central: o SDK não sabe para onde envia

Esta é a decisão mais importante do projeto.

O SDK **produz eventos** e os entrega a uma interface chamada `Transport`:

```php
interface Transport
{
    public function send(Event $event): void;
    public function sendBatch(array $events): void;
    public function flush(): bool;
    public function close(): void;
}
```

É só isso que ele conhece do mundo exterior. Hoje existem três implementações
reais (`null`, `memory`, `file`) e uma quarta preparada para o futuro (`http`,
que falará com o Observer Server em Go).

**Consequência prática:** quando o servidor Go existir, nenhum collector,
nenhum DTO e nenhuma linha da API pública muda. Troca-se `OBSERVER_TRANSPORT=file`
por `OBSERVER_TRANSPORT=http` e pronto.

---

## 4. O caminho completo de um evento

Vamos seguir um caso real: **uma query lenta durante uma requisição HTTP**.

```
 ┌─ 1 ─────────────────────────────────────────────────────────────┐
 │ A aplicação executa User::where('email', $email)->first()       │
 │ O Laravel dispara o evento nativo QueryExecuted                  │
 └───────────────────────────┬─────────────────────────────────────┘
                             ▼
 ┌─ 2 ─ QueryCollector ────────────────────────────────────────────┐
 │ Estava escutando QueryExecuted desde o boot.                     │
 │ Lê SQL, tempo, bindings, conexão; descobre em que linha do SEU   │
 │ código a query nasceu; marca slow = true (250ms > limiar).       │
 │ Monta um QueryPayload (objeto tipado, não array solto).          │
 └───────────────────────────┬─────────────────────────────────────┘
                             ▼
 ┌─ 3 ─ Event ─────────────────────────────────────────────────────┐
 │ Envelope imutável: id (UUID), tipo (query), nível (warning),     │
 │ mensagem, timestamp, payload.                                    │
 └───────────────────────────┬─────────────────────────────────────┘
                             ▼
 ┌─ 4 ─ EventPipeline ─────────────────────────────────────────────┐
 │ 1. EnabledProcessor    SDK ligado? ambiente ok? collector ativo? │
 │ 2. IgnoreProcessor     a query está na blacklist? (migrations…)  │
 │ 3. SamplingProcessor   passou no sorteio da taxa de amostragem?  │
 │ 4. ContextProcessor    anexa projeto, ambiente, release, usuário,│
 │                        servidor, runtime PHP e trace_id          │
 │ 5. ScrubbingProcessor  mascara senhas/tokens, remove PII, trunca │
 │ 6. BeforeSendProcessor último veto do desenvolvedor              │
 │                                                                  │
 │ Qualquer um pode devolver null → evento descartado ali mesmo.    │
 └───────────────────────────┬─────────────────────────────────────┘
                             ▼
 ┌─ 5 ─ EventBuffer ───────────────────────────────────────────────┐
 │ Guarda em memória. Quando junta 50 eventos, drena de uma vez.    │
 └───────────────────────────┬─────────────────────────────────────┘
                             ▼
 ┌─ 6 ─ Serializer + Transport ────────────────────────────────────┐
 │ JsonSerializer converte o lote; FileTransport grava em disco.    │
 │ Isso acontece no terminate() da request — depois que o usuário   │
 │ já recebeu a resposta.                                           │
 └─────────────────────────────────────────────────────────────────┘
```

O ponto sutil do passo 6: **o custo do SDK não entra no tempo de resposta**.
O middleware `CaptureRequest` faz o flush em `terminate()`, que o Laravel executa
após enviar a resposta ao navegador.

---

## 5. Anatomia do projeto

### `src/Contracts/` — as fronteiras

Seis interfaces. São o único ponto de acoplamento entre as camadas.

| Interface | Papel |
|---|---|
| `Transport` | para onde os eventos vão |
| `EventProcessor` | um estágio da pipeline |
| `Collector` | quem traduz um sinal do Laravel em evento |
| `Serializer` | como o evento vira texto |
| `Payload` | um payload de domínio que sabe virar array |
| `ClientInterface` | a API pública (o que a aplicação chama) |

### `src/DTO/` — os dados

`Event` é o **envelope imutável** de tudo. Todo evento do sistema, seja um erro
fatal ou um cache hit, tem exatamente esta forma:

```php
final readonly class Event
{
    public string    $id;         // UUID v4
    public EventType $type;       // exception | query | request | ...
    public Severity  $level;      // debug ... emergency
    public string    $message;
    public float     $timestamp;
    public array     $payload;    // específico do tipo
    public array     $context;    // transversal (usuário, servidor, trace)
    public array     $tags;
}
```

Ser `readonly` é intencional: métodos como `withTags()` e `mergeContext()`
devolvem **cópias**, nunca modificam o original. Isso torna a pipeline segura —
um processor não consegue corromper o evento para os processors seguintes.

Em `DTO/Payloads/` ficam os nove payloads tipados (`QueryPayload`,
`ExceptionPayload`, `RequestPayload`…). O collector monta um objeto com campos
nomeados, e não um array solto — se alguém errar um nome de campo, o erro aparece
na análise estática, não em produção.

### `src/Enums/` — o vocabulário

- `EventType`: os 11 tipos de evento. **Os valores são contrato com o servidor Go** —
  renomear um case quebra o protocolo.
- `Severity`: os 8 níveis PSR-3, com peso numérico. É o que permite perguntar
  "esse evento é pelo menos `error`?" (`isAtLeast()`) e converter tanto níveis
  PSR-3 quanto os códigos numéricos do Monolog.

### `src/Collectors/` — os tradutores

Aqui mora todo o conhecimento sobre o Laravel. Nove classes, cada uma escutando
os eventos nativos do framework. Detalhados na seção 6.

`AbstractCollector` é a base e garante quatro coisas para todos:

1. **Falha isolada** — qualquer exceção durante a coleta é engolida e registrada
   internamente. *Um bug no SDK nunca pode derrubar a aplicação do cliente.*
2. **Guard anti-recursão** — enquanto um evento está sendo gravado, nenhum outro
   collector produz eventos. Sem isso, a query que o SDK dispara ao gravar viraria
   um evento, que dispararia outra query, que viraria outro evento… laço infinito.
3. **Deduplicação de exceptions** — a mesma exception costuma chegar por dois
   caminhos (o `report()` do Laravel e o `Log::error(..., ['exception' => $e])`
   que ele emite em seguida). Um mapa estático compartilhado garante um evento só.
4. **Resolução tardia** — o collector pega o cliente e a config do container **a
   cada evento**, e não no construtor. Os listeners são registrados uma vez no
   boot, mas o container pode ser reconfigurado depois (é o que `Observer::fake()`
   faz nos testes). Segurar a instância deixaria o collector escrevendo em um
   transporte obsoleto.

### `src/Pipeline/` — o processamento

`EventPipeline` executa os processors em ordem e para no primeiro que devolver
`null`. Ela também **conta quantos eventos cada processor descartou** — isso
responde à pergunta "por que meu evento não apareceu?".

Se um processor lançar exceção, a pipeline registra internamente e **segue com o
evento como estava** antes dele. Um processor quebrado degrada a qualidade do
dado, não a coleta inteira.

### `src/Transport/` — as saídas

| Driver | O que faz | Quando usar |
|---|---|---|
| `NullTransport` | descarta e conta | SDK desligado sem remover código |
| `MemoryTransport` | acumula em array com teto | testes automatizados |
| `FileTransport` | grava JSON Lines com `LOCK_EX` e rotação | desenvolvimento local |
| `HttpTransport` | **contrato pronto, envio pendente** | quando o servidor Go existir |

O `TransportManager` resolve o driver da config, memoiza instâncias e aceita
drivers de terceiros via `extend('kafka', fn ($config) => new KafkaTransport(...))` —
extensibilidade sem obrigar ninguém a herdar de nada.

**Sobre o formato JSON Lines** do `FileTransport`: um evento por linha. Isso
permite `tail -f`, processar com `jq` sem carregar o arquivo inteiro na memória,
e sobreviver a escritas concorrentes de vários processos.

### `src/Buffer/` — o acúmulo

`EventBuffer` guarda eventos e drena em lote. É a troca central de desempenho do
SDK: **50 eventos custam 1 operação de I/O em vez de 50**.

O preço: se o processo morrer de forma abrupta, o que estiver no buffer se perde.
Daí as três redes de segurança — flush no `terminate()` da request, flush no
`terminating()` da aplicação e um `register_shutdown_function` que pega até erro
fatal e `exit()`.

Com `buffer.enabled = false`, ele vira passthrough e cada evento vai direto ao
transporte (útil para depurar localmente).

### `src/Services/` — regras de negócio do SDK

- **`Redactor`** — percorre estruturas arbitrárias **uma única vez** aplicando
  quatro coisas ao mesmo tempo: mascaramento por nome de chave, truncamento de
  strings, corte de arrays longos e limite de profundidade. Também normaliza
  objetos, enums e datas para algo serializável.
- **`ExceptionFormatter`** — converte um `Throwable` em payload. Marca quais
  frames são código da aplicação (`in_app`) versus vendor, anexa os trechos de
  código ao redor da linha do erro, segue exceptions encadeadas (até 3 níveis) e
  calcula um **fingerprint** estável — classe + arquivo + linha, sem a mensagem,
  porque mensagens costumam conter IDs variáveis que estragariam o agrupamento.
- **`ScopeManager`** — o contexto da execução corrente: usuário logado, tags,
  breadcrumbs (janela deslizante das N últimas) e o `trace_id` que correlaciona
  todos os eventos de uma mesma request ou job.

### `src/Support/` — utilitários puros

`Uuid` (v4 sem dependências), `Clock` (fonte de tempo congelável em testes),
`Str` (casamento por wildcard *ou* regex, truncamento) e `Configuration`.

**`Configuration` merece uma nota:** é um snapshot imutável da config, tirado
quando o singleton é resolvido. Ler o repositório do Laravel a cada query custaria
caro em caminho quente. A consequência é que alterar config em runtime exige
recriar o singleton — é exatamente o que `Observer::fake()` faz.

### `src/Log/`, `src/Middleware/`, `src/Facades/`, `src/Helpers/`

- `ObserverHandler` — handler Monolog, caminho alternativo para quem quiser
  instrumentar **um canal específico** em vez de tudo.
- `CaptureRequest` — middleware empilhado automaticamente; só existe para o flush
  no `terminate()`.
- `Facades\Observer` — a API elegante, com `fake()` e os asserts embutidos.
- `functions.php` — o helper global `observer()`.

### `src/Testing/`

`ObserverFake` troca o transporte por `MemoryTransport`, desliga o buffer (para
que o assert enxergue o evento no instante em que é gerado) e reconstrói todos os
singletons do SDK. Fornece asserts prontos: `assertCaptured`, `assertLogged`,
`assertEvent`, `assertMetric`, `assertNothingRecorded`, `assertCount`.

---

## 6. O que é capturado, e de onde

Nenhum destes exige configuração manual. Todos se registram no `boot()` do
provider e podem ser desligados individualmente.

### Exceptions — `ExceptionCollector`

Captura por **três caminhos complementares**, porque nenhum sozinho pega tudo:

1. `$handler->reportable()` — pega tudo que passa pelo `report()` do Laravel,
   que é o caminho de praticamente toda exception em uma app Laravel.
2. `set_exception_handler()` — pega exceptions não tratadas fora do ciclo do
   framework (bootstrap, scripts CLI).
3. `register_shutdown_function()` — pega **erros fatais** (`E_ERROR`, `E_PARSE`,
   memória esgotada), que nem sequer geram um `Throwable` capturável.

Coleta: classe, mensagem, código, arquivo, linha, stacktrace com trechos de
código, exceptions encadeadas, fingerprint e se foi tratada ou não.

Por padrão ignora o ruído operacional: `ValidationException`,
`AuthenticationException`, `NotFoundHttpException`, `TokenMismatchException`.
A verificação é por hierarquia — ignorar uma classe base ignora as filhas.

### Logs — `LogCollector`

Escuta `MessageLogged`, disparado pelo logger do Laravel em todos os níveis.
Escolhemos esse caminho em vez de um handler Monolog acoplado a um canal porque
**funciona com qualquer canal já configurado**, sem exigir que o desenvolvedor
edite o `logging.php`.

Detalhe útil: um `Log::error('falhou', ['exception' => $e])` vira um **evento de
exception completo** (com stacktrace), não um log de texto — e a deduplicação
impede que ele conte duas vezes.

### Banco de dados — `QueryCollector`

Escuta `QueryExecuted` (o mesmo evento por trás de `DB::listen`).

Coleta SQL, tempo, bindings, conexão e driver. Duas coisas a mais que valem
destaque:

- **Origem**: percorre o backtrace pulando frames do framework e do vendor para
  responder *"qual linha do meu código disparou essa query?"*.
- **Bindings normalizados**: datas viram string, enums viram valor, objetos viram
  nome de classe e strings binárias viram `[binary N bytes]` — nada que quebre a
  serialização depois.

Queries acima do limiar são marcadas como `slow` e sobem para nível `warning`.
Há também um modo `only_slow` para ambientes de altíssimo volume.

### Requisições HTTP — `RequestCollector`

Escuta `RequestHandled`, o único ponto em que método, rota, status e duração
existem ao mesmo tempo. Usa `LARAVEL_START` como marco inicial, o que inclui até
o tempo de bootstrap do framework.

Coleta método, URL, rota, action, status, duração, headers (mascarados), query
string, pico de memória — e, opcionalmente, IP, user agent e corpo.

O nível do evento é derivado do resultado: 5xx vira `error`, 4xx vira `warning`,
e uma resposta acima do limiar de lentidão também vira `warning`.

### Chamadas HTTP de saída — `HttpClientCollector`

Escuta `RequestSending`, `ResponseReceived` e `ConnectionFailed`. Cobre tanto o
Laravel HTTP Client quanto o Guzzle que roda por baixo dele.

Como o Laravel não fornece a duração pronta, o collector cronometra entre o envio
e a resposta, indexando pela identidade do objeto de request (com teto de memória
para não vazar em workers longevos).

### Filas — `QueueCollector`

Escuta `JobQueued`, `JobProcessing`, `JobProcessed` e `JobFailed`.

Por padrão registra só o **desfecho** (processado/falhou) — enfileiramento e
início dobrariam o volume sem acrescentar muito. Ambos podem ser ligados.

Faz duas coisas além de coletar:

- **Limpa o escopo entre jobs.** Em um worker o processo é reaproveitado; sem
  isso, o usuário e as tags de um job vazariam para o próximo.
- **Faz flush ao fim de cada job**, porque o worker continua vivo e o buffer
  poderia ficar pendurado indefinidamente.

Job falho carrega a exception formatada inteira, marcada como não tratada.

### Tarefas agendadas — `ScheduleCollector`

Escuta `ScheduledTaskStarting`, `Finished`, `Failed` e `Skipped`.

Responde ao que o cron não responde: *a task rodou? quanto demorou?* O evento
`skipped` é tão importante quanto o `failed` — uma task que nunca executa por
causa de um `withoutOverlapping` travado é uma falha silenciosa clássica.

Captura também a saída do comando, quando a task foi configurada com
`sendOutputTo()`.

### Cache — `CacheCollector`

Escuta `CacheHit`, `CacheMissed`, `KeyWritten` e `KeyForgotten`. Coleta operação,
chave, store, TTL e tags.

É o collector de maior volume em aplicações reais — por isso a config já vem com
amostragem de 10% para eventos de cache, e uma lista de chaves internas ignoradas.

### Comandos artisan — `CommandCollector`

Escuta `CommandStarting` e `CommandFinished`. **Desligado por padrão**: com o
scheduler ativo, `schedule:run` a cada minuto viraria ruído puro. Útil quando
comandos de negócio (importações, fechamentos) precisam de acompanhamento.

---

## 7. Privacidade e dados sensíveis

Duas proteções distintas, aplicadas antes de qualquer serialização:

**Mascaramento de segredos** — recursivo, por nome de chave, sem diferenciar
maiúsculas e aceitando wildcard. Cobre senhas, tokens, chaves de API, cartões,
CPF/CNPJ, chaves privadas. Headers têm lista própria (`authorization`, `cookie`,
`x-api-key`…) e são normalizados para minúsculas antes da comparação.

**Remoção de PII** — IP, user agent, corpo da requisição e os campos pessoais do
usuário (e-mail, nome) ficam **de fora por padrão**, para conformidade com
LGPD/GDPR. O **ID** do usuário é preservado: sem ele não há como correlacionar
erros por conta, e um ID interno não identifica a pessoa fora do sistema.

Para ligar tudo: `OBSERVER_SEND_DEFAULT_PII=true`.

---

## 8. Desempenho: o que fizemos para não pesar

| Técnica | Ganho | Preço | Decisão |
|---|---|---|---|
| Buffer + lote | 1 I/O em vez de N | perda em morte abrupta | adotado, com 3 redes de segurança |
| Flush no `terminate()` | custo fora do tempo de resposta | — | adotado |
| Amostragem por tipo | corta volume linearmente | perde fidelidade | adotado (cache 10% por padrão) |
| Serialização tardia | não serializa o que será descartado | — | adotado |
| Limites rígidos | payload previsível | dado truncado | adotado |
| Portão barato no topo | descarta cedo o que não interessa | — | `EnabledProcessor` é o 1º |

**Regra que nunca é amostrada:** exceptions e qualquer evento de nível `error` ou
acima passam sempre. Perder 90% das queries é aceitável; perder um erro de
produção não é.

**Limites rígidos:** tamanho de string, itens por array, profundidade, tamanho do
buffer e do lote. O SDK **não consegue** consumir memória sem teto.

---

## 9. Configuração

Tudo vive em `config/observer.php`, comentado seção por seção:

| Seção | O que controla |
|---|---|
| `enabled`, `environments` | liga/desliga global e por ambiente |
| `project`, `environment`, `release`, `server_name` | identidade anexada a todo evento |
| `transport` | driver e opções de cada um |
| `buffer` | tamanho, ligado/desligado, flush no shutdown |
| `sample_rate`, `sample_rates` | amostragem global e por tipo |
| `collectors` | cada collector com suas próprias opções |
| `scrubbing` | chaves, headers, máscara, PII |
| `limits` | os tetos rígidos |
| `context` | o que anexar de runtime/servidor/usuário |
| `before_send` | callback final do desenvolvedor |
| `debug` | expõe falhas internas do SDK no log da app |

Configuração mínima para começar:

```dotenv
OBSERVER_ENABLED=true
OBSERVER_PROJECT=minha-api
OBSERVER_TRANSPORT=file
OBSERVER_FILE_PATH=/var/log/observer.ndjson
```

---

## 10. A API que o desenvolvedor usa

```php
use Observer\Facades\Observer;

// Erros
Observer::capture($exception, ['order_id' => 42]);

// Logs estruturados
Observer::log('error', 'Pagamento recusado', ['gateway' => 'stripe']);

// Eventos de negócio
Observer::event('checkout.completed', ['total' => 199.90]);

// Métricas
Observer::metric('queue.depth', 128, ['queue' => 'default']);

// Contexto da execução
Observer::withUser(['id' => $user->id]);
Observer::withTags(['tenant' => 'acme']);
Observer::breadcrumb('cart', 'Item adicionado', ['sku' => 'X1']);

Observer::flush();
```

Três formas equivalentes de chegar ao cliente:

```php
Observer::capture($e);                              // facade
observer()->capture($e);                            // helper global
public function __construct(private ClientInterface $observer) {}  // injeção
```

A injeção é a preferível — mantém o código do usuário testável e desacoplado da
implementação concreta.

E nos testes da aplicação:

```php
$fake = Observer::fake();

$this->post('/pedidos', [...]);

$fake->assertCaptured(PaymentFailedException::class)
     ->assertEvent('checkout.completed');
```

---

## 11. Qualidade e verificação

Estado atual da suíte, executada nesta máquina:

```
PHPUnit    68 testes / 185 asserts    OK
Pint       preset Laravel             passed
PHPStan    nível 6                    no errors
```

- **Testes unitários** (sem framework): DTOs, enums, redator, pipeline e cada
  processor, buffer, os quatro transportes, serializador.
- **Testes de integração** (Orchestra Testbench): bindings do provider, facade,
  helper, `fake()`, e **cada collector contra eventos reais do Laravel** —
  incluindo os casos de borda (query lenta, 5xx, rota ignorada, job falho,
  exception logada, collector desligado, PII removida, mascaramento ponta a ponta).

**Ressalva de ambiente:** o PHP 8.5 desta máquina não tem `ext-dom`, exigida pelo
PHPUnit. A suíte roda localmente no PHP 8.2 (`composer update
--ignore-platform-req=php`). O código é compatível de 8.2 a 8.5; o alvo declarado
no `composer.json` continua sendo 8.3+, a ser validado no CI.

---

## 12. O que ainda não existe

| Item | Situação |
|---|---|
| `HttpTransport` (envio real) | contrato e classe prontos; falta implementar `dispatch()` quando o servidor existir |
| Observer Server (API Go) | outra etapa do projeto — autenticação, projetos, recepção de eventos, dashboard |
| Publicação no Packagist | precisa de repositório próprio e tag de versão |
| Workflow de CI | matriz PHP 8.3/8.4 × Laravel 11/12 ainda não montada |

O `HttpTransport` já tem definido: `POST {endpoint}/api/v1/events`, autenticação
por bearer token, corpo em lote, gzip acima de 1KB, retry com backoff exponencial
e jitter, timeouts curtos. Falta apenas o cliente HTTP dentro de `dispatch()`.

---

## 13. Resumo do que foi construído

```
73 arquivos PHP        9 collectors        4 transportes
6 contracts            6 processors        9 payloads tipados
2 enums                3 serviços          68 testes
```

Fases concluídas: **1 a 9** — arquitetura, estrutura, configuração, transporte,
collectors, pipeline, facade, integração automática com o Laravel e testes.

Pendente: **fase 10** (Packagist) e a integração futura com o Observer Server.
