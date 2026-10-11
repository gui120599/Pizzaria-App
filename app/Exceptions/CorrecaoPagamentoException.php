<?php

namespace App\Exceptions;

use RuntimeException;

/** Correção de lançamento recusada (ver App\Services\CorrecaoPagamentoService) — a mensagem vai para o usuário. */
class CorrecaoPagamentoException extends RuntimeException {}
