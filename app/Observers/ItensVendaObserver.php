<?php

namespace App\Observers;

use App\Models\ItensVenda;
use App\Models\Produto;

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
        if ($item->ehMultiSabor()) {
            $this->congelarCustoDosSabores($item);

            return;
        }

        if ((float) $item->item_venda_custo_unitario > 0) {
            return;
        }

        $produto = $item->produto;

        if ($produto) {
            $item->item_venda_custo_unitario = round($produto->custoUnitario(), 8);
        }
    }

    /**
     * Pizza de sabores: custo de cada sabor congelado no próprio JSON e o
     * custo da pizza = Σ custo do sabor × percentual. Usar o custo só do
     * produto da linha (1º sabor) distorceria o CMV.
     */
    private function congelarCustoDosSabores(ItensVenda $item): void
    {
        $produtos = Produto::whereIn('id', array_column($item->sabores(), 'produto_id'))->get()->keyBy('id');

        $custoPizza = 0.0;
        $sabores = [];

        foreach ($item->sabores() as $sabor) {
            $custoSabor = (float) ($sabor['custo_unitario'] ?? $produtos->get($sabor['produto_id'])?->custoUnitario() ?? 0);
            $sabor['custo_unitario'] = round($custoSabor, 8);
            $custoPizza += $custoSabor * ((float) $sabor['percentual']) / 100;
            $sabores[] = $sabor;
        }

        $item->item_venda_sabores = $sabores;

        if ((float) $item->item_venda_custo_unitario <= 0) {
            $item->item_venda_custo_unitario = round($custoPizza, 8);
        }
    }
}
