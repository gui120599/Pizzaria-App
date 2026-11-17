<?php

namespace App\Exceptions;

/**
 * O teto de brindes da campanha de promoção adicional (promoad_qtd_total)
 * acabou na hora de gravar a oferta. Diferente das outras indisponibilidades,
 * quem finaliza o pedido pode seguir sem o brinde (concessão parcial).
 */
class PromocaoAdicionalEsgotadaException extends PromocaoIndisponivelException {}
