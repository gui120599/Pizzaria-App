<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Mesa;
use App\Http\Requests\StoreMesaRequest;
use App\Http\Requests\UpdateMesaRequest;

class MesaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $mesas = Mesa::all();
        return view('app.mesa', ['mesas' => $mesas]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('app.mesa.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreMesaRequest $request)
    {
        $mesa = Mesa::create([
            'mesa_nome' => $request->input('mesa_nome'),
            'mesa_status' => $request->input('mesa_status'),
        ]);

        // Redireciona para a página da mesa recém-criada
        return redirect()->route('mesa')->with('success', 'Mesa criada com sucesso!');
    }

    /**
     * Display the specified resource.
     */
    public function show(Mesa $mesa)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Mesa $mesa)
    {
        return view('app.mesa.edit', ['mesa' => $mesa]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMesaRequest $request, Mesa $mesa)
    {
        $mesa->update([
            'mesa_nome' => $request->input('mesa_nome'),
            'mesa_status' => $request->input('mesa_status'),
        ]);

        // Redireciona para a página da mesa recém-atualizada
        return redirect()->route('mesa')->with('success', 'Mesa atualizada com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Mesa $mesa)
    {
        $mesa->delete();

        return redirect()->route('mesa')->with('success', 'Mesa inativada com sucesso!');
    }

    /**
     * Show form for inactive mesas.
     */
    public function inactive()
    {
        $mesas_inativas = Mesa::onlyTrashed()->get();

        return view('app.mesa.inactive', ['mesas' => $mesas_inativas]);
    }

    /**
     * Active object.
     */
    public function active(Mesa $mesa, $id)
    {
        $mesa = Mesa::withTrashed()->find($id);

        if (!$mesa) {
            return redirect('/Mesa')->with('error', 'Mesa não encontrada!');
        }
        $mesa->restore();
        return redirect('/Mesa')->with('success', 'Mesa Ativada com sucesso');
    }
}
