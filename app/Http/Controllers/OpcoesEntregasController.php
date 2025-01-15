<?php

namespace App\Http\Controllers;

use App\Models\OpcoesEntregas;
use App\Http\Requests\StoreOpcoesEntregasRequest;
use App\Http\Requests\UpdateOpcoesEntregasRequest;

class OpcoesEntregasController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $opcoes_entregas = OpcoesEntregas::all();
        return view('app.opcoes_entregas.index', ['opcoes_entregas' => $opcoes_entregas]);
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
    public function store(StoreOpcoesEntregasRequest $request)
    {
        $opcoes_entregas = OpcoesEntregas::create([
            'opcaoentrega_nome' => $request->input('opcaoentrega_nome'),
        ]);

        $opcoes_entregas->save();

        // Redireciona para a página da opcoes_entregas recém-criada
        return redirect()->route('opcoes_entregas')->with('success', 'OpcoesEntregas criada com sucesso!');

    }

    /**
     * Display the specified resource.
     */
    public function show(OpcoesEntregas $opcoes_entregas)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(OpcoesEntregas $opcoes_entregas)
    {
        return view('app.opcoes_entregas.edit', ['opcoes_entregas' => $opcoes_entregas]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateOpcoesEntregasRequest $request, OpcoesEntregas $opcoes_entregas)
    {
        $opcoes_entregas->update([
            'opcaoentrega_nome' => $request->input('opcaoentrega_nome'),
        ]);

        // Redireciona para a página da opcoes_entregas recém-criada
        return redirect()->route('opcoes_entregas')->with('success', 'OpcoesEntregas atualizada com sucesso!');

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(OpcoesEntregas $opcoes_entregas, $id)
    {
        $opcoes_entregas = OpcoesEntregas::find($id);

        if (!$opcoes_entregas) {
            return redirect('/OpcoesEntregas')->with('error', 'OpcoesEntregas não encontrada!');
        }
        $opcoes_entregas->delete();
        return redirect('/OpcoesEntregas')->with('success', 'OpcoesEntregas Inativada com sucesso');
    }

    /**
     * Show form Inactives.
     */
    public function inactive()
    {
        $opcoes_entregas_inativas = OpcoesEntregas::onlyTrashed()->get();

        return view('app.opcoes_entregas.inactive', ['opcoes_entregas_inativadas' => $opcoes_entregas_inativas]);
    }

     /**
     * Active object.
     */
    public function active(OpcoesEntregas $opcoes_entregas, $id)
    {
        $opcoes_entregas = OpcoesEntregas::withTrashed()->find($id);

        if (!$opcoes_entregas) {
            return redirect('/OpcoesEntregas')->with('error', 'OpcoesEntregas não encontrada!');
        }
        $opcoes_entregas->restore();
        return redirect('/OpcoesEntregas')->with('success', 'OpcoesEntregas Ativada com sucesso');
    }
}
