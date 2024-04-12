<?php

namespace App\Http\Controllers;

use App\Models\MovimentacaoProduto;
use App\Http\Requests\StoreMovimentacaoProdutoRequest;
use App\Http\Requests\UpdateMovimentacaoProdutoRequest;
use App\Models\Categoria;
use App\Models\Produto;

class MovimentacaoProdutoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function indexEntrada(MovimentacaoProduto $mov_produto, Categoria $categoria)
    {
        $categorias = $categoria::all();
        $mov_produtos = $mov_produto::all();
        $produtos = Produto::with(['mov_produto' => function ($query) {
            $query->selectRaw('mov_produto_id, SUM(CASE WHEN mov_tipo = "ENTRADA" THEN mov_quantidade ELSE -mov_quantidade END) as saldo')
                ->groupBy('mov_produto_id');
        }])->get();

        return view('app.entrada_produto.index', ['mov_produtos' => $mov_produtos, 'produtos' => $produtos, 'categorias' => $categorias]);
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
    public function storeEntrada(StoreMovimentacaoProdutoRequest $request)
    {
        // Crie uma nova instância do modelo e atribua os valores
        $movimentacaoProduto = new MovimentacaoProduto([
            'mov_produto_id' => $request->input('mov_produto_id'),
            'mov_quantidade' => str_replace(',', '.', $request->input('mov_quantidade')),
            'mov_tipo' => $request->input('mov_tipo'),
            'mov_motivo' => $request->input('mov_motivo'),
            'mov_venda_id' => $request->input('mov_venda_id'),
            // O 'user_id' será preenchido automaticamente pelo evento 'creating'
        ]);

        // Salve o registro no banco de dados
        $movimentacaoProduto->save();

        // Retorne a resposta desejada, por exemplo, redirecionar para uma página ou retornar uma resposta JSON
        return redirect()->route('entrada_produto')->with('success', 'Entrada realizada com sucesso!');
    }

    /**
     * Display the specified resource.
     */
    public function show(MovimentacaoProduto $movimentacaoProduto)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(MovimentacaoProduto $movimentacaoProduto)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMovimentacaoProdutoRequest $request, MovimentacaoProduto $movimentacaoProduto)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MovimentacaoProduto $movimentacaoProduto)
    {
        //
    }
}
