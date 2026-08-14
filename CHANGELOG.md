# Changelog

Todas as mudanças relevantes deste pacote são documentadas aqui.

O formato segue [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o
versionamento segue [SemVer](https://semver.org/lang/pt-BR/).

## [0.4.1] — 2026-08-14

### Corrigido

- **O SDK deixou de observar a si mesmo.** Em produção, no PHP 8.5, cada envio
  de lote gerava um evento novo — e o volume de logs nunca chegava a zero:

  ```
  envio → curl_close() emite um deprecated do PHP 8.5
        → Laravel loga como warning
          → LogCollector captura
            → vira evento no buffer
              → próximo envio ⟲
  ```

  O `curl_close()` (`Transport/Http/CurlSender.php`) foi removido. Desde o PHP
  8.0 o `curl_init()` devolve um objeto `CurlHandle` em vez de resource, a
  função virou no-op e o 8.5 a depreca — o handle é liberado pelo coletor de
  lixo. Era uma linha sem efeito nenhum sustentando um laço de ruído, cobrado
  ao cliente em ingestão e retenção.

  A causa, porém, não era o `curl_close`: era o guard anti-recursão viver
  dentro do `AbstractCollector` e cobrir apenas o **registro** de um evento. O
  envio é outro momento, com o guard já liberado, então qualquer aviso emitido
  durante ele passava direto.

  O guard virou `Support\SelfGuard`, é reentrante (contador, não booleano — o
  envio pode acontecer de dentro de um registro quando `buffer.size = 1`) e
  agora envolve também `HttpTransport::dispatchPending()`. Nada que o PHP emita
  do caminho de envio — deprecated de curl, warning de socket, notice de
  serialização — vira evento.

  Como segunda camada, o `LogCollector` descarta mensagens cuja origem seja um
  arquivo do próprio pacote, pegando o resíduo que o guard não cobre: avisos
  disparados no boot do provider, em destrutores ou em shutdown handlers.

  Avisos vindos do código da **aplicação** continuam sendo capturados
  normalmente: o guard fecha o SDK para si mesmo, não silencia o cliente.

## [0.4.0] — 2026-08-14

### Adicionado

- **Suporte a Laravel 10 e PHP 8.1.** O SDK exigia Laravel 11+ e PHP 8.2+, o
  que impedia a instalação na maior parte das aplicações em produção hoje — o
  `composer require` falhava na resolução, antes mesmo de baixar nada.

  A única incompatibilidade real era sintática: 15 arquivos usavam `readonly
  class`, recurso de PHP 8.2. As propriedades passaram a ser marcadas
  individualmente com `readonly`, disponível desde o 8.1, sem mudar o
  comportamento — os objetos continuam imutáveis.

  Verificado instalando em um projeto Laravel 10.50 com PHP 8.1 e enviando um
  evento de verdade ao servidor.

## [0.3.0] — 2026-08-14

### Adicionado

- **`OBSERVER_DSN`** — credencial do projeto em uma variável só, no formato
  `https://<chave>@<host>/<id-do-projeto>`, copiada do painel. Substitui
  `OBSERVER_ENDPOINT` + `OBSERVER_API_KEY`, que continuam funcionando. Com o DSN
  definido, o transporte `http` passa a ser o padrão — ter a credencial é a
  intenção de enviar.

### Corrigido

- A configuração do pacote passa a ser fundida **em profundidade** com a
  publicada na aplicação. O `mergeConfigFrom` do Laravel funde só o primeiro
  nível, então o bloco `transport` do arquivo publicado substituía o do pacote
  inteiro: quem já tinha publicado o config antes desta versão não enxergaria a
  chave `transport.http.dsn`, e o sintoma seria "configurei o DSN e não envia
  nada".
- Aviso no log quando há DSN configurado mas o transporte em uso não é `http` —
  a combinação que antes falhava em silêncio.

### Atualizando da 0.2.x

Republique o config para receber o padrão de transporte sensível ao DSN:

```bash
php artisan vendor:publish --tag=observer-config --force
```

## [0.2.0] — 2026-08-14

### Adicionado

- **`OBSERVER_DSN`** — credencial do projeto em uma variável só, no formato
  `https://<chave>@<host>/<id-do-projeto>`, copiada do painel. Substitui
  `OBSERVER_ENDPOINT` + `OBSERVER_API_KEY`, que continuam funcionando. Com o DSN
  definido, o transporte `http` passa a ser o padrão — a presença da credencial
  é a intenção de enviar.

- **Transporte HTTP funcional.** `OBSERVER_TRANSPORT=http` passa a enviar os
  eventos ao Observer Server: lote, `Authorization: Bearer`, gzip acima de 1 KB
  e retry com backoff exponencial. Antes o driver coletava e descartava.
- `HttpSender` com dois mecanismos — `ext-curl` (preferido, único com timeout de
  conexão separado do total) e wrappers nativos como fallback. Nenhuma
  dependência nova: um SDK não deve impor cliente HTTP à aplicação hospedeira.
- `transport.http.total_budget_ms` (default 3000) limita o tempo somado de todas
  as tentativas. O envio acontece no `terminate()`, dentro do ciclo de request
  da sua aplicação — sem esse teto, o pior caso somaria segundos à resposta.

### Corrigido

- `sendBatch()` acumulava eventos sem nunca despachar, e `flush()` esvaziava o
  buffer retornando `false` sem enviar. Mesmo com `dispatch()` implementado,
  nada sairia.

## [0.1.1] — 2026-08-14

### Corrigido

- O pacote publicado carregava `agent.md`, `claude.md` e `docs/` para dentro do
  `vendor/` de quem instalava. Eram anotações internas de desenvolvimento — o
  `agent.md`, inclusive, descrevia uma estrutura de diretórios que o código não
  tem mais. Os três saíram do repositório e agora vivem só na máquina de
  desenvolvimento.
- Removidos do README os links para `docs/`, que apontariam para arquivos
  inexistentes.

## [0.1.0] — 2026-08-14

Primeira versão publicada. O pacote passa a ser instalável via
`composer require guilhermeviana-observer/sdk`.

### Adicionado

- Coleta automática de exceptions, logs, queries, requests, jobs de fila,
  tarefas agendadas, cache, chamadas HTTP externas e comandos Artisan.
- Pipeline de eventos com seis processadores encadeados: habilitação, ignore,
  amostragem, contexto, mascaramento de dados sensíveis e `before_send`.
- Transportes `null`, `memory` e `file` (JSON Lines com rotação por tamanho).
- Buffer com descarga automática ao atingir o lote, ao fim da request, no
  encerramento da aplicação e ao fim de um comando Artisan.
- Facade `Observer` com `capture()`, `log()`, `event()`, `metric()`,
  `withUser()`, `withTags()` e `breadcrumb()`.
- `ObserverFake` para asserções em testes da aplicação.
- Mascaramento de dados sensíveis ligado por padrão, com `send_default_pii`
  desligado.

### Notas desta versão

- **O transporte `http` não está implementado.** `HttpTransport::dispatch()`
  lança `TransportException::notImplemented()`; os eventos são coletados e
  descartados em silêncio. O contrato com o Observer Server (endpoint, headers,
  compressão gzip, política de retry) já está fixado na classe, mas o envio
  depende do servidor entrar no ar. Use `file` até lá.
- A API pública ainda pode mudar antes da `1.0.0`.

### Requisitos

- PHP 8.2 ou superior
- Laravel 11 ou 12
