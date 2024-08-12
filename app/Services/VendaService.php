<?php

namespace App\Services;

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

        $venda_valor_base_calculo = 0;
        $venda_valor_icms = 0;
        $venda_valor_pis = 0;
        $venda_valor_cofins = 0;
        $venda_valor_itens = 0;
        $venda_valor_frete = $venda->venda_valor_frete ?? 0;
        $venda_valor_acrescimo = $venda->venda_valor_acrescimo ?? 0;
        $venda_valor_desconto = 0;
        $venda_valor_total = 0;

        foreach ($itens as $item) {
            $venda_valor_base_calculo += $item->item_venda_valor_base_calculo;
            $venda_valor_icms += $item->item_venda_valor_icms;
            $venda_valor_pis += $item->item_venda_valor_pis;
            $venda_valor_cofins += $item->item_venda_valor_cofins;
            $venda_valor_itens += $item->item_venda_valor_unitario;
            $venda_valor_desconto += $item->item_venda_desconto;
            $venda_valor_total += $item->item_venda_valor;
        }

        $venda->venda_valor_base_calculo = $venda_valor_base_calculo;
        $venda->venda_valor_icms = $venda_valor_icms;
        $venda->venda_valor_pis = $venda_valor_pis;
        $venda->venda_valor_cofins = $venda_valor_cofins;
        $venda->venda_valor_itens = $venda_valor_itens;
        $venda->venda_valor_desconto = $venda_valor_desconto;
        $venda->venda_valor_total = $venda_valor_total + $venda_valor_acrescimo + $venda_valor_frete;

        $venda->save();

        return $venda;
    }
}
