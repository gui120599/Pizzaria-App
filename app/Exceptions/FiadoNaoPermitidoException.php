<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Pedido marcado como fiado / a prazo para cliente sem cadastro, sem limite
 * de crédito configurado ou com crédito menor que o total do pedido.
 */
class FiadoNaoPermitidoException extends RuntimeException {}
