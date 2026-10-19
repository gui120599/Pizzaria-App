<?php

namespace App\Exceptions;

use App\Enums\StatusPedidoEnum;
use App\Models\Pedido;
use RuntimeException;

/**
 * A exclusão pedida não é segura para o estado atual do pedido: ela deixaria
 * estoque, venda, cobrança ou comissão sem estorno. A mensagem é escrita para
 * ser exibida direto ao operador.
 */
class PedidoNaoPodeSerExcluidoException extends RuntimeException
{
    public static function statusNaoPermitido(Pedido $pedido, StatusPedidoEnum $status): self
    {
        return new self(
            "O pedido {$pedido->id} está em \"{$status->label()}\". Cancele o pedido antes de excluir: envolve estoque e cozinha."
        );
    }

    public static function temVenda(Pedido $pedido): self
    {
        return new self("O pedido {$pedido->id} tem venda ou itens vendidos e não pode ser excluído.");
    }

    public static function cobrancaStone(Pedido $pedido): self
    {
        return new self("O pedido {$pedido->id} tem cobrança na maquininha pendente ou paga. Cancele a cobrança antes.");
    }

    public static function comissaoAtiva(Pedido $pedido): self
    {
        return new self("O pedido {$pedido->id} tem comissão de influenciador ativa e não pode ser excluído.");
    }

    public static function observacaoObrigatoria(): self
    {
        return new self('Informe uma observação (mínimo de 10 caracteres) para o motivo "Outro".');
    }
}
