<?php

namespace App\Exceptions;

/**
 * A Stone pode ter criado o pedido, mas não sabemos: timeout, falha de rede ou
 * HTTP 5xx no POST /orders. Diferente de uma recusa comprovada (4xx) — aqui o
 * pedido pode estar aberto na maquininha, então o envio NUNCA gera um
 * StonePedido novo: o mesmo registro é reenviado com a mesma Idempotency-Key.
 */
class StoneResultadoDesconhecidoException extends StoneConnectException
{
    private ?int $stonePedidoId = null;

    /** Registro (stone_pedidos) que ficou Indeterminado, para a tela acompanhá-lo e reconsultá-lo. */
    public function paraStonePedido(int $stonePedidoId): static
    {
        $this->stonePedidoId = $stonePedidoId;

        return $this;
    }

    public function stonePedidoId(): ?int
    {
        return $this->stonePedidoId;
    }
}
