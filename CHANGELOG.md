# Changelog

Todas as mudanças relevantes deste pacote são documentadas aqui.

O formato segue [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o
versionamento segue [SemVer](https://semver.org/lang/pt-BR/).

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
