<?php

declare(strict_types=1);
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return [

    /*
    |--------------------------------------------------------------------------
    | Chave geral
    |--------------------------------------------------------------------------
    |
    | Desligar aqui faz o SDK virar um no-op completo: nenhum collector é
    | registrado e nenhum evento é produzido. Custo próximo de zero.
    |
    */

    'enabled' => env('OBSERVER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Identidade da aplicação
    |--------------------------------------------------------------------------
    |
    | Metadados anexados a todo evento. O Observer Server usa 'project' para
    | rotear os eventos e 'release' para comparar deploys.
    |
    */

    'project' => env('OBSERVER_PROJECT', env('APP_NAME', 'laravel')),

    'environment' => env('OBSERVER_ENVIRONMENT', env('APP_ENV', 'production')),

    'release' => env('OBSERVER_RELEASE'),

    'server_name' => env('OBSERVER_SERVER_NAME', gethostname() ?: null),

    /*
    |--------------------------------------------------------------------------
    | Ambientes habilitados
    |--------------------------------------------------------------------------
    |
    | Lista vazia = todos os ambientes. Útil para evitar ruído em 'testing'.
    |
    */

    'environments' => array_values(array_filter(
        explode(',', (string) env('OBSERVER_ENVIRONMENTS', ''))
    )),

    /*
    |--------------------------------------------------------------------------
    | Transporte
    |--------------------------------------------------------------------------
    |
    | null   → descarta tudo (SDK desligado sem remover o código)
    | memory → guarda em memória (testes)
    | file   → grava JSON Lines em disco (desenvolvimento local)
    | http   → reservado: enviará ao Observer Server (API Go)
    |
    */

    'transport' => [

        /*
         * Com OBSERVER_DSN definido, o driver padrão passa a ser 'http' — a
         * presença da credencial É a intenção de enviar. Sem ela, 'file'.
         * OBSERVER_TRANSPORT explícito continua vencendo os dois.
         */
        'driver' => env('OBSERVER_TRANSPORT', env('OBSERVER_DSN') ? 'http' : 'file'),

        'file' => [
            'path' => env('OBSERVER_FILE_PATH', storage_path('logs/observer.ndjson')),
            'max_size' => (int) env('OBSERVER_FILE_MAX_SIZE', 10 * 1024 * 1024),
            'max_files' => (int) env('OBSERVER_FILE_MAX_FILES', 5),
            'permission' => 0644,
        ],

        'http' => [
            /*
             * Credencial do projeto em uma string só, no formato
             * https://<chave>@<host>/<id-do-projeto>, copiada do painel ao
             * criar o projeto. Quando presente, ela preenche endpoint e chave.
             */
            'dsn' => env('OBSERVER_DSN'),

            // Alternativa ao DSN, para quem prefere separar os valores.
            'endpoint' => env('OBSERVER_ENDPOINT', 'https://api.observer.dev'),
            'api_key' => env('OBSERVER_API_KEY'),
            'timeout' => (float) env('OBSERVER_HTTP_TIMEOUT', 2.0),
            'connect_timeout' => (float) env('OBSERVER_HTTP_CONNECT_TIMEOUT', 1.0),
            'retries' => (int) env('OBSERVER_HTTP_RETRIES', 2),
            'retry_delay_ms' => (int) env('OBSERVER_HTTP_RETRY_DELAY', 200),

            // Teto para TODAS as tentativas somadas. O envio acontece dentro do
            // ciclo de request da sua aplicação (no terminate), então este é o
            // pior caso que o SDK pode acrescentar ao tempo de resposta.
            'total_budget_ms' => (int) env('OBSERVER_HTTP_TOTAL_BUDGET_MS', 3000),
            'compress' => env('OBSERVER_HTTP_COMPRESS', true),
            'compress_threshold' => 1024,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Buffer
    |--------------------------------------------------------------------------
    |
    | Acumula eventos em memória e entrega em lote. Reduz I/O drasticamente,
    | ao custo de perder o buffer em caso de morte abrupta do processo — por
    | isso o shutdown handler.
    |
    */

    'buffer' => [
        'enabled' => env('OBSERVER_BUFFER_ENABLED', true),
        'size' => (int) env('OBSERVER_BUFFER_SIZE', 50),
        'flush_on_shutdown' => env('OBSERVER_FLUSH_ON_SHUTDOWN', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Amostragem
    |--------------------------------------------------------------------------
    |
    | 1.0 = tudo, 0.1 = 10%. Exceptions raramente devem ser amostradas;
    | queries e cache, quase sempre.
    |
    */

    'sample_rate' => (float) env('OBSERVER_SAMPLE_RATE', 1.0),

    'sample_rates' => [
        'exception' => 1.0,
        'log' => 1.0,
        'request' => (float) env('OBSERVER_SAMPLE_REQUESTS', 1.0),
        'query' => (float) env('OBSERVER_SAMPLE_QUERIES', 1.0),
        'cache' => (float) env('OBSERVER_SAMPLE_CACHE', 0.1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Collectors
    |--------------------------------------------------------------------------
    |
    | Cada collector é opt-out e tem opções próprias. Nenhuma configuração
    | manual é exigida do desenvolvedor: tudo é assinado nos eventos nativos
    | do Laravel durante o boot do provider.
    |
    */

    'collectors' => [

        'exceptions' => [
            'enabled' => env('OBSERVER_CAPTURE_EXCEPTIONS', true),
            'stack_trace_limit' => 50,
            'code_context_lines' => 5,
            'ignore' => [
                AuthenticationException::class,
                AuthorizationException::class,
                ValidationException::class,
                NotFoundHttpException::class,
                TokenMismatchException::class,
            ],
        ],

        'logs' => [
            'enabled' => env('OBSERVER_CAPTURE_LOGS', true),
            'level' => env('OBSERVER_LOG_LEVEL', 'debug'),
            'channels' => [],
        ],

        'queries' => [
            'enabled' => env('OBSERVER_CAPTURE_QUERIES', true),
            'slow_threshold_ms' => (float) env('OBSERVER_QUERY_SLOW_MS', 200),
            'only_slow' => env('OBSERVER_QUERY_ONLY_SLOW', false),
            'capture_bindings' => env('OBSERVER_CAPTURE_BINDINGS', true),
            'capture_origin' => true,
            'ignore' => ['/^select .* from `?migrations`?/i'],
        ],

        'requests' => [
            'enabled' => env('OBSERVER_CAPTURE_REQUESTS', true),
            'capture_headers' => true,
            'capture_body' => env('OBSERVER_CAPTURE_REQUEST_BODY', false),
            'ignore_paths' => ['horizon*', 'telescope*', '_debugbar*', 'livewire*'],
            'slow_threshold_ms' => (float) env('OBSERVER_REQUEST_SLOW_MS', 1000),
        ],

        'http_client' => [
            'enabled' => env('OBSERVER_CAPTURE_HTTP_CLIENT', true),
            'capture_headers' => false,
            'ignore_hosts' => [],
        ],

        'queue' => [
            'enabled' => env('OBSERVER_CAPTURE_QUEUE', true),
            'capture_queued' => false,
            'capture_processing' => false,
            'ignore_jobs' => [],
        ],

        'schedule' => [
            'enabled' => env('OBSERVER_CAPTURE_SCHEDULE', true),
            'capture_output' => true,
        ],

        'cache' => [
            'enabled' => env('OBSERVER_CAPTURE_CACHE', true),
            'operations' => ['hit', 'miss', 'write', 'forget'],
            'ignore_keys' => ['observer:*', 'illuminate:queue:restart'],
        ],

        'commands' => [
            'enabled' => env('OBSERVER_CAPTURE_COMMANDS', false),
            'ignore' => ['schedule:run', 'schedule:finish', 'queue:work', 'queue:listen', 'horizon*'],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Mascaramento de dados sensíveis
    |--------------------------------------------------------------------------
    |
    | Aplicado recursivamente em payload e contexto, antes de qualquer
    | serialização. Chaves e headers batem sem diferenciar maiúsculas.
    |
    */

    'scrubbing' => [

        'enabled' => true,

        'mask' => '[FILTERED]',

        'keys' => [
            'password', 'password_confirmation', 'secret', 'token', 'api_key',
            'apikey', 'access_token', 'refresh_token', 'authorization',
            'credit_card', 'card_number', 'cvv', 'cvc', 'ssn', 'cpf', 'cnpj',
            'private_key', 'client_secret', 'session', 'remember_token',
        ],

        'headers' => [
            'authorization', 'cookie', 'set-cookie', 'x-api-key', 'x-csrf-token',
            'x-xsrf-token', 'proxy-authorization',
        ],

        /*
        | Informação pessoalmente identificável (IP, user agent, dados do usuário
        | além do ID). Desligado por padrão para conformidade com LGPD/GDPR.
        */
        'send_default_pii' => env('OBSERVER_SEND_DEFAULT_PII', false),

    ],

    /*
    |--------------------------------------------------------------------------
    | Limites rígidos
    |--------------------------------------------------------------------------
    |
    | Garantem que o SDK jamais consuma memória sem teto nem produza payloads
    | gigantes que o servidor recusaria.
    |
    */

    'limits' => [
        'max_string_length' => 4096,
        'max_array_items' => 100,
        'max_depth' => 8,
        'max_breadcrumbs' => (int) env('OBSERVER_MAX_BREADCRUMBS', 50),
        'max_payload_bytes' => 512 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Contexto
    |--------------------------------------------------------------------------
    */

    'context' => [
        'runtime' => true,
        'server' => true,
        'user' => true,
        'tags' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Hook before_send
    |--------------------------------------------------------------------------
    |
    | Callable que recebe o Event já processado e retorna o Event (modificado
    | ou não) ou null para descartá-lo. Último ponto de controle antes do buffer.
    |
    | 'before_send' => fn (\Observer\DTO\Event $event) => $event,
    |
    */

    'before_send' => null,

    /*
    |--------------------------------------------------------------------------
    | Depuração do próprio SDK
    |--------------------------------------------------------------------------
    |
    | Com debug ligado, falhas internas do SDK são escritas no log da aplicação
    | em vez de silenciadas. Nunca ligue em produção.
    |
    */

    'debug' => env('OBSERVER_DEBUG', false),

];
