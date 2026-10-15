<?php

namespace App\Exceptions;

use RuntimeException;

/** Movimentação financeira inválida para o estado atual (mensagem pronta para o usuário). */
class FinanceiroException extends RuntimeException {}
