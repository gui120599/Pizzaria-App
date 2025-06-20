<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreComboProdutoRequest;
use App\Http\Requests\UpdateComboProdutoRequest;
use App\Models\ComboProduto;
use App\Models\ItensComboProduto;
use App\Models\Produto;

class ComboProdutoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $combos = ComboProduto::all();
        $produtos = Produto::all();
        return view('app.combo_produto.index', ['combo_produtos' => $combos, 'produtos' => $produtos]);
    }
    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreComboProdutoRequest $request)
    {
        $data = $request->all();

        // Cria o combo principal
        $combo = ComboProduto::create([
            'combo_produto_nome' => $data['combo_produto_nome'],
            'combo_produto_valor' => str_replace(',', '.', $data['combo_produto_valor']),
            'combo_produto_foto' => $data['combo_produto_foto'],
            'combo_produto_cardapio' => isset($data['combo_produto_cardapio']),
            'combo_produto_promocional' => isset($data['combo_produto_promocional']),
        ]);

        // Upload da foto, se presente
        if ($request->hasFile('combo_produto_foto') && $request->file('combo_produto_foto')->isValid()) {
            $foto = $request->file('combo_produto_foto');
            $combo->saveFoto($foto);
        }

        // Insere os itens do combo
        foreach ($data['itens'] as $item) {
            ItensComboProduto::create([
                'item_combo_produto_combo_id' => $combo->id,
                'item_combo_produto_produto_id' => $item['produto_id'],
                'item_combo_produto_quantidade_produto' => $item['quantidade'],
                'item_combo_produto_valor_produto' => str_replace(',', '.', $item['valor_produto']),
                'item_combo_produto_valor_desconto' => str_replace(',', '.', $item['valor_desconto'] ?? 0),
                'item_combo_produto_valor_total' => str_replace(',', '.', $item['valor_total']),
            ]);
        }

        return redirect()->route('combo_produtos')->with('success', 'Combo cadastrado com sucesso!');
    }

    /**
     * Display the specified resource.
     */
    public function show(ComboProduto $comboProduto)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ComboProduto $comboProduto)
    {
        return view('combo_produtos.edit', compact('comboProduto'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateComboProdutoRequest $request, ComboProduto $comboProduto)
    {
        $comboProduto->update($request->validated());
        return redirect()->route('combo_produtos')->with('success', 'Combo atualizado com sucesso.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ComboProduto $comboProduto)
    {
        $comboProduto->delete();
        return redirect()->route('combo_produtos')->with('success', 'Combo removido com sucesso.');
    }
}