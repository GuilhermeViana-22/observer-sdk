<?php

declare(strict_types=1);

namespace Observer\Support;

/**
 * O SDK é o MEIO, nunca o fim.
 *
 * Nada que acontece dentro do Observer pode virar um evento do Observer. Não é
 * preferência de ruído — é a diferença entre uma ferramenta de observabilidade
 * e um laço que se alimenta:
 *
 *     SDK envia o lote
 *       → curl_close() emite um deprecated do PHP 8.5
 *         → Laravel loga como warning
 *           → LogCollector captura
 *             → vira evento no buffer
 *               → próximo envio  ⟲
 *
 * Cada envio produzia pelo menos um evento novo, que exigia outro envio. O
 * volume nunca chegava a zero, e era ruído puro — cobrado ao cliente em
 * ingestão e retenção.
 *
 * O guard anterior vivia dentro do AbstractCollector e cobria apenas o
 * REGISTRO de um evento. O curl_close acontece no ENVIO, outro momento, com o
 * guard já liberado — por isso o warning passava direto. Aqui a marcação é uma
 * só e vale para as duas fases.
 *
 * É estático de propósito: o laço atravessa objetos diferentes (o collector que
 * captura e o transporte que envia não se conhecem), então um flag de instância
 * não fecharia nada.
 */
final class SelfGuard
{
    private static int $depth = 0;

    /**
     * Estamos dentro do SDK neste instante?
     *
     * Os collectors consultam antes de capturar qualquer coisa.
     */
    public static function active(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Executa marcando que o controle está dentro do SDK.
     *
     * Reentrante: o envio pode acontecer de dentro de um registro (buffer com
     * size=1 despacha na hora), e um flag booleano seria liberado pelo bloco
     * interno enquanto o externo ainda está rodando. O contador não tem esse
     * furo.
     *
     * @template T
     *
     * @param callable(): T $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    /**
     * Zera a marcação. Existe para os testes: um callback que lance uma
     * exception fatal não deve deixar o guard preso entre um teste e outro.
     */
    public static function reset(): void
    {
        self::$depth = 0;
    }
}
