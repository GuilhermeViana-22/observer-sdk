# Changelog

Todas as mudanças relevantes deste pacote são documentadas aqui.

O formato segue [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o
versionamento segue [SemVer](https://semver.org/lang/pt-BR/).

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
