<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreItensComboProdutoRequest;
use App\Http\Requests\UpdateItensComboProdutoRequest;
use App\Models\ItensComboProduto;

class ItensComboProdutoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $itens = ItensComboProduto::with(['combo', 'produto'])->latest()->paginate(50);
        return view('app.itens_combo.index', compact('itens'));
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
    public function store(StoreItensComboProdutoRequest $request)
    {
        ItensComboProduto::create($request->validated());
        return back()->with('success', 'Item do combo adicionado com sucesso!');
    }

    /**
     * Display the specified resource.
     */
    public function show(ItensComboProduto $itensComboProduto)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ItensComboProduto $itensComboProduto)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateItensComboProdutoRequest $request, ItensComboProduto $item)
    {
        $item->update($request->validated());
        return back()->with('success', 'Item do combo atualizado com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ItensComboProduto $item)
    {
        $item->delete();
        return back()->with('success', 'Item do combo removido com sucesso!');
    }
}
