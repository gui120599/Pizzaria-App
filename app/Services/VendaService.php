<?php

namespace App\Services;

use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Venda;

class VendaService
{
    public function atualizarValoresdaVenda($venda_id)
    {
        $venda = Venda::find($venda_id);
        if (!$venda) {
            return false; // Venda não encontrada
        }

        $itens = $venda->itensVenda;
        $pagamentos = $venda->pagamentos;

        $venda_valor_base_calculo = 0;
        $venda_valor_icms = 0;
        $venda_valor_pis = 0;
        $venda_valor_cofins = 0;
        $venda_valor_itens = 0;
        // Soma o frete de todos os pedidos que têm itens lançados nesta venda
        $pedidoIds = ItensPedido::where('item_pedido_venda_id', $venda_id)
            ->whereNotNull('item_pedido_pedido_id')
            ->distinct()
            ->pluck('item_pedido_pedido_id');

        $fretePedidos = $pedidoIds->isEmpty()
            ? 0
            : Pedido::whereIn('id', $pedidoIds)->sum('pedido_valor_frete');

        // Frete sempre recalculado dos pedidos; zera quando não há pedidos vinculados
        $venda_valor_frete = $fretePedidos;
        $venda_valor_acrescimo = 0;
        $venda_valor_desconto = 0;
        $venda_valor_total = 0;
        $venda_valor_pago = 0;
        $venda_valor_troco = 0;

        foreach ($itens as $item) {
            $venda_valor_base_calculo += $item->item_venda_valor_base_calculo;
            $venda_valor_icms += $item->item_venda_valor_icms;
            $venda_valor_pis += $item->item_venda_valor_pis;
            $venda_valor_cofins += $item->item_venda_valor_cofins;
            $venda_valor_itens += $item->item_venda_valor;
            $venda_valor_desconto += $item->item_venda_desconto;
            $venda_valor_total += $item->item_venda_valor;
        }

        foreach($pagamentos as $pagamento){
            $venda_valor_pago += $pagamento->pg_venda_valor_pagamento;
            $venda_valor_acrescimo += $pagamento->pg_venda_valor_acrescimo;
            $venda_valor_desconto += $pagamento->pg_venda_valor_desconto;
            $venda_valor_troco += $pagamento->pg_venda_valor_troco;
        }

        $venda->venda_valor_base_calculo = $venda_valor_base_calculo;
        $venda->venda_valor_icms = $venda_valor_icms;
        $venda->venda_valor_pis = $venda_valor_pis;
        $venda->venda_valor_cofins = $venda_valor_cofins;
        $venda->venda_valor_itens = $venda_valor_itens;
        $venda->venda_valor_frete = $venda_valor_frete;
        $venda->venda_valor_desconto = $venda_valor_desconto;
        $venda->venda_valor_total = $venda_valor_total + $venda_valor_frete;
        $venda->venda_valor_pago = $venda_valor_pago;
        $venda->venda_valor_acrescimo = $venda_valor_acrescimo;
        // Troco agora é a soma dos trocos registrados em cada pagamento
        $venda->venda_valor_troco = $venda_valor_troco;


        $venda->save();

        return $venda;
    }
}
