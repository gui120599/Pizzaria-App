<?php

namespace App\Exceptions;

use App\Enums\StatusPedidoEnum;
use App\Models\Pedido;
use RuntimeException;

/**
 * A transição de status pedida não se aplica ao estado atual do pedido.
 *
 * O caso mais comum não é erro de programação: são dois operadores (ou duas
 * abas) avançando o mesmo pedido ao mesmo tempo. Quem chega depois recebe esta
 * exception em vez de aplicar a transição de novo — que, na entrada em
 * PREPARANDO, baixaria o estoque duas vezes.
 */
class TransicaoPedidoInvalidaException extends RuntimeException
{
    public static function jaAvancou(Pedido $pedido, StatusPedidoEnum $atual): self
    {
        return new self(
            "O pedido {$pedido->id} já está em \"{$atual->label()}\" — outra pessoa deve ter atualizado antes."
        );
    }

    public static function destinoInvalido(Pedido $pedido, StatusPedidoEnum $atual, StatusPedidoEnum $destino): self
    {
        return new self(
            "Não é possível mover o pedido {$pedido->id} de \"{$atual->label()}\" para \"{$destino->label()}\"."
        );
    }

    public static function semProximoStatus(Pedido $pedido, StatusPedidoEnum $atual): self
    {
        return new self(
            "O pedido {$pedido->id} está em \"{$atual->label()}\" e não tem próxima etapa."
        );
    }

    public static function statusDesconhecido(Pedido $pedido): self
    {
        return new self(
            "O pedido {$pedido->id} tem um status fora do esperado (\"{$pedido->pedido_status}\")."
        );
    }

    public static function naoPodeSerCancelado(Pedido $pedido, StatusPedidoEnum $atual): self
    {
        return new self(
            "O pedido {$pedido->id} está em \"{$atual->label()}\" e não pode mais ser cancelado."
        );
    }

    public static function jaPago(Pedido $pedido): self
    {
        return new self(
            "O pedido {$pedido->id} não pode ser cancelado porque já está pago (venda {$pedido->pedido_venda_id})."
        );
    }

    public static function sessaoMesaFechada(Pedido $pedido, string $statusSessao): self
    {
        return new self(
            "O pedido {$pedido->id} não pode ser cancelado porque a sessão da mesa está {$statusSessao}."
        );
    }
}
