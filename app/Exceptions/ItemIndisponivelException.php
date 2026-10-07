<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Produto que não existe mais ou que o canal não vende (oculto do cardápio,
 * fora do cardápio do garçom) — ver LancamentoItemPedidoService::precificar().
 */
class ItemIndisponivelException extends RuntimeException {}
