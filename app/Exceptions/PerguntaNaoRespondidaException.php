<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Respostas do item fora da regra da pergunta: obrigatória sem escolha ou
 * mais opções que o máximo — ver LancamentoItemPedidoService::precificar().
 */
class PerguntaNaoRespondidaException extends RuntimeException {}
