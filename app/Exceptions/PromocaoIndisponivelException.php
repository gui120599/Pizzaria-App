<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A promoção relâmpago acabou (ou saiu da janela) entre o momento em que o
 * cliente montou o carrinho e o momento em que o pedido foi confirmado.
 */
class PromocaoIndisponivelException extends RuntimeException {}
