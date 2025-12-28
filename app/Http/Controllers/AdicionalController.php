<?php

namespace App\Http\Controllers;

use App\Models\Adicional;
use App\Http\Requests\StoreAdicionalRequest;
use App\Http\Requests\UpdateAdicionalRequest;

class AdicionalController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $adicionais = Adicional::all();
        return view('app.adicional.index', ['adicionais' => $adicionais]);
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
    public function store(StoreAdicionalRequest $request)
    {
        $adicional = Adicional::create([
            'adicional_nome' => $request->input('adicional_nome'),
            'adicional_valor' => $request->input('adicional_valor') ? str_replace(',', '.', $request->input('adicional_valor')) : '0.00',
        ]);

        if ($request->hasFile('adicional_foto')) {
            $foto = $request->file('adicional_foto');
            $adicional->saveFoto($foto);

        }

        $adicional->save();
        return redirect()->route('adicional')->with('success', 'Adicional salvo com sucesso!');


    }

    /**
     * Display the specified resource.
     */
    public function show(Adicional $adicional)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Adicional $adicional)
    {
        return view('app.adicional.edit', ['adicional' => $adicional]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAdicionalRequest $request, Adicional $adicional)
    {
        // Atualizar os dados do registro
        $adicional->update([
            'adicional_nome' => $request->input('adicional_nome'),
            'adicional_valor' => $request->input('adicional_valor') ? str_replace(',', '.', $request->input('adicional_valor')) : '0.00',
        ]);

        // Verificar se há uma nova foto enviada
        if ($request->hasFile('adicional_foto')) {
            $foto = $request->file('adicional_foto');
            $adicional->saveFoto($foto); // Método saveFoto já lida com o salvamento da imagem
        }

        return redirect()->route('adicional')->with('success', 'Adicional atualizado com sucesso!');
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Adicional $adicional,$id)
    {
        $adicional = Adicional::find($id);

        if (!$adicional) {
            return redirect('/Adicional')->with('error', 'adicional não encontrado!');
        }

        $adicional->delete();

        return redirect('/Adicional')->with('success', 'adicional Inativado com sucesso!');
    }


    /**
     * Show form Inactives.
     */
    public function inactive()
    {
        $adicionais_inativos = Adicional::onlyTrashed()->get();

        return view('app.adicional.inactive', ['adicionais' => $adicionais_inativos]);
    }


    /**
     * Active object.
     */
    public function active(Adicional $adicional, $id)
    {
        $adicional = Adicional::withTrashed()->find($id);

        if (!$adicional) {
            return redirect('/Adicional')->with('error', 'Adicional não encontrado!');
        }

        $adicional->restore();

        return redirect('/Adicional')->with('success', 'Adicional Ativado com sucesso!');
    }
}
