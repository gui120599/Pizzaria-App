<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreComboProdutoRequest;
use App\Http\Requests\UpdateComboProdutoRequest;
use App\Models\ComboProduto;

class ComboProdutoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $combos = ComboProduto::all();
        return view('combo_produtos.index', compact('combos'));
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
        ComboProduto::create($request->validated());
        return redirect()->route('combo_produtos.index')->with('success', 'Combo criado com sucesso.');
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
        return redirect()->route('combo_produtos.index')->with('success', 'Combo atualizado com sucesso.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ComboProduto $comboProduto)
    {
        $comboProduto->delete();
        return redirect()->route('combo_produtos.index')->with('success', 'Combo removido com sucesso.');
    }
}
