<?php

declare(strict_types=1);

namespace Observer\Exceptions;

use RuntimeException;

/**
 * Base de todas as exceções do SDK.
 *
 * Exceções do SDK nunca devem escapar para a aplicação em runtime: são
 * capturadas pelo InternalLogger. Elas existem para falhas de configuração
 * (detectadas no boot) e para os testes do próprio pacote.
 */
class ObserverException extends RuntimeException {}
