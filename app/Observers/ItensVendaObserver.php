<?php

namespace App\Observers;

use App\Models\ItensVenda;

class ItensVendaObserver
{
    /**
     * Congela o custo do produto no momento em que o item entra na venda.
     *
     * Usa custoUnitario() (custo da ficha técnica quando existe; senão o
     * custo médio WAC). Assim o CMV do período fica fiel mesmo que o custo
     * do produto mude depois. Só preenche se ainda não veio definido.
     */
    public function creating(ItensVenda $item): void
    {
        if ((float) $item->item_venda_custo_unitario > 0) {
            return;
        }

        $produto = $item->produto;

        if ($produto) {
            $item->item_venda_custo_unitario = round($produto->custoUnitario(), 8);
        }
    }
}
