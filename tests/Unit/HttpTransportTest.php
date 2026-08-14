<?php

declare(strict_types=1);

namespace Observer\Tests\Unit;

use Observer\Contracts\HttpSender;
use Observer\DTO\Event;
use Observer\Enums\EventType;
use Observer\Enums\Severity;
use Observer\Serializers\JsonSerializer;
use Observer\Transport\Http\HttpResponse;
use Observer\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Sender que não toca na rede: registra o que recebeu e devolve o que mandarmos.
 */
final class FakeSender implements HttpSender
{
    /** @var list<array{url: string, body: string, headers: array<string, string>}> */
    public array $calls = [];

    /** @param list<HttpResponse> $responses */
    public function __construct(private array $responses = [], private ?\Throwable $throw = null) {}

    public function send(string $url, string $body, array $headers, float $timeout, float $connectTimeout): HttpResponse
    {
        $this->calls[] = ['url' => $url, 'body' => $body, 'headers' => $headers];

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return array_shift($this->responses) ?? new HttpResponse(202);
    }
}

final class HttpTransportTest extends TestCase
{
    private function event(string $message = 'oi'): Event
    {
        return Event::make(EventType::Log, $message, ['message' => $message], Severity::Info);
    }

    private function transport(FakeSender $sender, int $retries = 2, int $maxBatch = 50): HttpTransport
    {
        return new HttpTransport(
            endpoint: 'https://observer.test',
            apiKey: 'lsec_1234567890abcdef',
            serializer: new JsonSerializer(),
            timeout: 2.0,
            connectTimeout: 1.0,
            retries: $retries,
            retryDelayMs: 1,
            compress: true,
            compressThreshold: 1024,
            logger: null,
            sender: $sender,
            totalBudgetMs: 3000,
            maxBatch: $maxBatch,
        );
    }

    public function test_202_limpa_o_pendente(): void
    {
        $sender = new FakeSender([new HttpResponse(202)]);
        $transport = $this->transport($sender);

        $transport->sendBatch([$this->event()]);

        $this->assertTrue($transport->flush());
        $this->assertCount(1, $sender->calls);
    }

    public function test_url_e_autorizacao_seguem_o_contrato(): void
    {
        $sender = new FakeSender();
        $this->transport($sender)->sendBatch([$this->event()]);
        $this->transport($sender)->flush();

        $transport = $this->transport($sender);
        $transport->sendBatch([$this->event()]);
        $transport->flush();

        $call = end($sender->calls);
        $this->assertSame('https://observer.test/api/v1/events', $call['url']);
        $this->assertSame('Bearer lsec_1234567890abcdef', $call['headers']['Authorization']);
        $this->assertSame('observer-php-sdk/1.0', $call['headers']['User-Agent']);
    }

    /**
     * 4xx significa "reenviar produziria o mesmo erro". Retentar aqui seria
     * gastar o tempo do request do cliente para receber a mesma recusa.
     */
    public function test_4xx_nao_retenta(): void
    {
        $sender = new FakeSender([new HttpResponse(400, '{"error":"invalid_envelope"}')]);
        $transport = $this->transport($sender);

        $transport->sendBatch([$this->event()]);

        $this->assertFalse($transport->flush());
        $this->assertCount(1, $sender->calls);
    }

    /**
     * 503 é o backpressure do servidor — exatamente o caso que precisa de nova
     * tentativa. Se o servidor mandasse 429 (que é 4xx), o SDK descartaria.
     */
    public function test_503_retenta_ate_o_limite(): void
    {
        $sender = new FakeSender([
            new HttpResponse(503),
            new HttpResponse(503),
            new HttpResponse(503),
        ]);
        $transport = $this->transport($sender, retries: 2);

        $transport->sendBatch([$this->event()]);
        $transport->flush();

        // retries: 2 => 3 tentativas no total.
        $this->assertCount(3, $sender->calls);
    }

    public function test_503_seguido_de_202_tem_sucesso(): void
    {
        $sender = new FakeSender([new HttpResponse(503), new HttpResponse(202)]);
        $transport = $this->transport($sender);

        $transport->sendBatch([$this->event()]);

        $this->assertTrue($transport->flush());
        $this->assertCount(2, $sender->calls);
    }

    /**
     * Uma chave errada não fica certa entre um flush e outro.
     */
    public function test_401_desliga_o_transporte(): void
    {
        $sender = new FakeSender([new HttpResponse(401)]);
        $transport = $this->transport($sender);

        $transport->sendBatch([$this->event()]);
        $transport->flush();

        $transport->sendBatch([$this->event('segundo')]);
        $transport->flush();

        $this->assertCount(1, $sender->calls, 'não deveria tentar de novo após 401');
    }

    public function test_corpo_grande_vai_comprimido(): void
    {
        $sender = new FakeSender();
        $transport = $this->transport($sender);

        $transport->sendBatch([$this->event(str_repeat('x', 4096))]);
        $transport->flush();

        $call = end($sender->calls);
        $this->assertSame('gzip', $call['headers']['Content-Encoding'] ?? null);
        $this->assertSame("\x1f\x8b", substr($call['body'], 0, 2), 'corpo deveria ser gzip');
    }

    public function test_corpo_pequeno_vai_sem_compressao(): void
    {
        $sender = new FakeSender();
        $transport = $this->transport($sender);

        $transport->sendBatch([$this->event()]);
        $transport->flush();

        $call = end($sender->calls);
        $this->assertArrayNotHasKey('Content-Encoding', $call['headers']);
        $this->assertJson($call['body']);
    }

    /**
     * O EventBuffer::add() não tem try/catch: uma exceção daqui subiria pelo
     * Log::info() da aplicação do cliente.
     */
    public function test_excecao_do_sender_nao_propaga(): void
    {
        $sender = new FakeSender([], new \RuntimeException('rede caiu'));
        $transport = $this->transport($sender);

        $transport->sendBatch([$this->event()]);

        $this->assertFalse($transport->flush());
    }

    public function test_lote_cheio_dispara_envio_sem_flush(): void
    {
        $sender = new FakeSender();
        $transport = $this->transport($sender, maxBatch: 2);

        $transport->sendBatch([$this->event('a')]);
        $this->assertCount(0, $sender->calls, 'não deveria enviar antes do teto');

        $transport->sendBatch([$this->event('b')]);
        $this->assertCount(1, $sender->calls, 'deveria enviar ao atingir o teto');
    }

    public function test_flush_sem_pendencia_e_sucesso(): void
    {
        $sender = new FakeSender();

        $this->assertTrue($this->transport($sender)->flush());
        $this->assertCount(0, $sender->calls);
    }
}
