<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Http\Requests\StoreCategoriaRequest;
use App\Http\Requests\UpdateCategoriaRequest;

class CategoriaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $categorias = Categoria::all();
        return view('app.categoria.index', ['categorias' => $categorias]);
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
    public function store(StoreCategoriaRequest $request)
    {
        $categoria = Categoria::create([
            'categoria_nome' => $request->input('categoria_nome'),
        ]);

        $categoria->save();

        // Redireciona para a página da categoria recém-criada
        return redirect()->route('categoria')->with('success', 'Categoria criada com sucesso!');

    }

    /**
     * Display the specified resource.
     */
    public function show(Categoria $categoria)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Categoria $categoria)
    {
        return view('app.categoria.edit', ['categoria' => $categoria]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCategoriaRequest $request, Categoria $categoria)
    {
        $categoria->update([
            'categoria_nome' => $request->input('categoria_nome'),
        ]);

        // Redireciona para a página da categoria recém-criada
        return redirect()->route('categoria')->with('success', 'Categoria atualizada com sucesso!');

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Categoria $categoria, $id)
    {
        $categoria = Categoria::find($id);

        if (!$categoria) {
            return redirect('/Categoria')->with('error', 'Categoria não encontrada!');
        }
        $categoria->delete();
        return redirect('/Categoria')->with('success', 'Categoria Inativada com sucesso');
    }

    /**
     * Show form Inactives.
     */
    public function inactive()
    {
        $categorias_inativas = Categoria::onlyTrashed()->get();

        return view('app.categoria.inactive', ['categorias' => $categorias_inativas]);
    }

     /**
     * Active object.
     */
    public function active(Categoria $categoria, $id)
    {
        $categoria = Categoria::withTrashed()->find($id);

        if (!$categoria) {
            return redirect('/Categoria')->with('error', 'Categoria não encontrada!');
        }
        $categoria->restore();
        return redirect('/Categoria')->with('success', 'Categoria Ativada com sucesso');
    }

}
