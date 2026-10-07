<?php

namespace App\Services;

use App\Models\ItensPedido;
use App\Models\Produto;
use App\Models\PromocaoAdicionalOferta;
use App\Models\PromocaoAdicionalRegra;

/**
 * Item já precificado pelo servidor, pronto para validar (estoque, limites de
 * promoção) e gravar — ver LancamentoItemPedidoService.
 */
final class LinhaPrecificada
{
    /**
     * @param  array<string, mixed>  $atributos  Campos item_pedido_* da linha (sem o pedido)
     * @param  list<array{id: int, nome: string, valor: float}>  $adicionais  Valor unitário de cada adicional
     * @param  Produto  $produto  Produto gravado na linha (o 1º sabor, na pizza de sabores)
     */
    public function __construct(
        public readonly array $atributos,
        public readonly array $adicionais,
        public readonly Produto $produto,
        public readonly ?PromocaoAdicionalRegra $regraOferta = null,
        public readonly ?PromocaoAdicionalOferta $oferta = null,
    ) {}

    public function semOferta(): self
    {
        return new self($this->atributos, $this->adicionais, $this->produto);
    }

    /** A linha sem gravar (prévia do carrinho, consumo de estoque). */
    public function previa(): ItensPedido
    {
        return (new ItensPedido($this->atributos))->setRelation('produto', $this->produto);
    }

    public function quantidade(): float
    {
        return (float) $this->atributos['item_pedido_quantidade'];
    }

    public function valor(): float
    {
        return (float) $this->atributos['item_pedido_valor'];
    }

    public function desconto(): float
    {
        return (float) $this->atributos['item_pedido_desconto'];
    }
}
