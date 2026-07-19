<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Um produto (ou insumo da ficha técnica) com modo de controle BLOQUEAR
 * não tem saldo suficiente para a quantidade pedida.
 */
class EstoqueInsuficienteException extends RuntimeException {}
