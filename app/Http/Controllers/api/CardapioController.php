<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\Produto;
use Illuminate\Http\Request;

class CardapioController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Produto::select(
            'produto_descricao',
            'produto_codimentacao',
            'produto_preco_venda',
            'produto_categoria_id'
        )->with('categoria:id,categoria_nome');

        if ($request->has('categoria_nome')) {
            $query->whereHas('categoria', function ($q) use ($request) {
                $q->where('categoria_nome', 'LIKE', '%' . $request->get('categoria_nome') . '%');
            });
        } 
        return $query->get();
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
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
