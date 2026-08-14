<?php

declare(strict_types=1);

namespace Observer\Transport\Http;

/**
 * Resultado de uma tentativa de envio.
 *
 * `status = 0` significa que a requisição não chegou a produzir resposta HTTP
 * (DNS, recusa de conexão, timeout). Esse caso é tratado como retriável, igual
 * a um 5xx: o servidor pode simplesmente estar reiniciando.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly ?string $error = null,
    ) {}

    public static function transportError(string $error): self
    {
        return new self(status: 0, error: $error);
    }

    /** 200..299 — o servidor aceitou o lote. */
    public function accepted(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * 4xx — descartar sem retentar.
     *
     * O contrato com o servidor é explícito: 4xx significa que reenviar
     * produziria exatamente o mesmo erro. Token inválido, envelope quebrado ou
     * lote grande demais não melhoram com insistência.
     */
    public function permanentFailure(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    /** 5xx ou falha de transporte — vale retentar. */
    public function retryable(): bool
    {
        return $this->status === 0 || $this->status >= 500;
    }
}
