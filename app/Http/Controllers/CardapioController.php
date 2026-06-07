<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\Produto;
use Illuminate\Http\Request;

class CardapioController extends Controller
{
    public function index()
    {
        $categorias = Categoria::whereHas('produtos', function ($query) {
            $query->where('produto_cardapio', true);
        })
            ->with([
                'produtos' => function ($query) {
                    $query->where('produto_cardapio', true);
                }
            ])
            ->where('categoria_cardapio',true)
            ->orderByRaw("
            CASE 
                WHEN categoria_nome LIKE 'Combo%' THEN 0 
                WHEN categoria_nome LIKE 'Pizza G%' THEN 1
                WHEN categoria_nome LIKE 'Pizza M%' THEN 2
                WHEN categoria_nome LIKE 'Pizza B%' THEN 3
                WHEN categoria_nome LIKE 'Pizza Q%' THEN 4
                WHEN categoria_nome LIKE 'Torta%' THEN 5
                WHEN categoria_nome LIKE 'Sandu%' THEN 6
                WHEN categoria_nome LIKE 'Pastel%' THEN 7
                WHEN categoria_nome LIKE 'Paneli%' THEN 8
                WHEN categoria_nome LIKE 'Refri%' THEN 9
                WHEN categoria_nome LIKE 'Cerveja%' THEN 10
                WHEN categoria_nome LIKE 'Suco%' THEN 11
                WHEN categoria_nome LIKE 'Cremes%' THEN 12
                ELSE 13
            END, categoria_nome
        ")
            ->get();

        $top10Ids = Produto::where('produto_cardapio', true)
            ->where('produto_destaque_mais_vendidos', true)
            ->where('produto_qtd_vendas', '>', 0)
            ->orderByDesc('produto_qtd_vendas')
            ->limit(10)
            ->pluck('id')
            ->all();

        $promocoes = Produto::where('produto_cardapio', true)
            ->where('produto_preco_promocional', '>', 0)
            ->with('categoria')
            ->orderByDesc('produto_qtd_vendas')
            ->get();

        $maisVendidos = Produto::where('produto_cardapio', true)
            ->where('produto_qtd_vendas', '>', 0)
            ->where('produto_destaque_mais_vendidos', true)
            ->with('categoria')
            ->orderByDesc('produto_qtd_vendas')
            ->limit(8)
            ->get();

        return view('cardapio', compact('categorias', 'promocoes', 'maisVendidos', 'top10Ids'));
    }
}
