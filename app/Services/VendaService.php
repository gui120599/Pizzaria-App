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
        $pagamentos = $venda->pagamentos;

        $venda_valor_base_calculo = 0;
        $venda_valor_icms = 0;
        $venda_valor_pis = 0;
        $venda_valor_cofins = 0;
        $venda_valor_itens = 0;
        $venda_valor_frete = $venda->venda_valor_frete ?? 0;
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
            $venda_valor_itens += $item->item_venda_valor_unitario * $item->item_venda_quantidade;
            $venda_valor_desconto += $item->item_venda_desconto;
            $venda_valor_total += $item->item_venda_valor;
        }

        foreach($pagamentos as $pagamento){
            $venda_valor_pago += $pagamento->pg_venda_valor_pagamento;
            $venda_valor_acrescimo += $pagamento->pg_venda_valor_acrescimo;
            $venda_valor_desconto += $pagamento->pg_venda_valor_desconto;
        }

        $venda->venda_valor_base_calculo = $venda_valor_base_calculo;
        $venda->venda_valor_icms = $venda_valor_icms;
        $venda->venda_valor_pis = $venda_valor_pis;
        $venda->venda_valor_cofins = $venda_valor_cofins;
        $venda->venda_valor_itens = $venda_valor_itens;
        $venda->venda_valor_desconto = $venda_valor_desconto;
        // Não precisa descontar o valor do frete, pois já vem descontando no valor do produto e a adição do acrescimos já vem do produto tbm!
        $venda->venda_valor_total = $venda_valor_total + $venda_valor_frete + $venda_valor_acrescimo; 
        $venda->venda_valor_pago = $venda_valor_pago;
        $venda->venda_valor_acrescimo = $venda_valor_acrescimo;
        
        if($venda->venda_valor_total < $venda->venda_valor_pago){
            $venda->venda_valor_troco = $venda->venda_valor_pago - $venda->venda_valor_total;
        }else{
            $venda->venda_valor_troco = 0;
        }
        

        $venda->save();

        return $venda;
    }
}
