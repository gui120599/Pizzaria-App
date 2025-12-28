<?php

namespace App\Http\Controllers;

use App\Models\Caixa;
use App\Http\Requests\StoreCaixaRequest;
use App\Http\Requests\UpdateCaixaRequest;

class CaixaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Caixa $caixa)
    {
        $caixas = $caixa::all();
        return view('app.caixa.index',['caixas' => $caixas]);
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
    public function store(StoreCaixaRequest $request)
    {
        Caixa::create($request->all());

        return redirect()->route('caixa')->with('success', 'Caixa criado com sucesso!');
    }

    /**
     * Display the specified resource.
     */
    public function show(Caixa $caixa)
    {
        return view('app.caixa.show',['caixa' => $caixa]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Caixa $caixa)
    {
        return view('app.caixa.edit',['caixa' => $caixa]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCaixaRequest $request, Caixa $caixa)
    {
        $caixa->update($request->all());

        return redirect()->route('caixa')->with('success', 'Caixa atualizado com sucesso!');
    }

    /**
     * Remover o recurso especificado do armazenamento.
     */
    public function destroy(Caixa $caixa, $id)
    {
        $caixa = Caixa::find($id);

        if (!$caixa) {
            return redirect('/Caixa')->with('error', 'Caixa não encontrado!');
        }

        $caixa->delete();

        return redirect('/Caixa')->with('success', 'Caixa Inativado com sucesso');
    }

      /**
     * Exibir formulário para Caixas Inativos.
     */
    public function inactive()
    {
        $caixasInativos = Caixa::onlyTrashed()->get();

        return view('app.caixa.inactive', ['caixas' => $caixasInativos]);
    }

    /**
     * Ativar objeto Caixa.
     */
    public function active(Caixa $caixa, $id)
    {
        $caixa = Caixa::withTrashed()->find($id);

        if (!$caixa) {
            return redirect('/Caixa')->with('error', 'Caixa não encontrado!');
        }

        $caixa->restore();

        return redirect('/Caixa')->with('success', 'Caixa ativado com sucesso');
    }
}
