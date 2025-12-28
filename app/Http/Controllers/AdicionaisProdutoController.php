<?php

namespace App\Http\Controllers;

use App\Models\AdicionaisProduto;
use App\Http\Requests\StoreAdicionaisProdutoRequest;
use App\Http\Requests\UpdateAdicionaisProdutoRequest;
use Illuminate\Http\Request;

class AdicionaisProdutoController extends Controller
{
    /**
     * Ativa ou desativa o relacionamento entre Adicional e Produto
     */
    public function ativar(Request $request)
    {
        try {
            $isActive = filter_var($request->input('isActive'), FILTER_VALIDATE_BOOLEAN);

            if (!$isActive) {
                AdicionaisProduto::firstOrCreate([
                    'ap_adicional_id' => $request->input('adicionalId'),
                    'ap_produto_id' => $request->input('produtoId'),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Adicional adicionado com sucesso!',
                ], 200);
            } else {
                AdicionaisProduto::where('ap_adicional_id', $request->input('adicionalId'))
                    ->where('ap_produto_id', $request->input('produtoId'))
                    ->delete();

                return response()->json([
                    'success' => true,
                    'message' => 'Adicional removido com sucesso!',
                ], 200);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar a solicitação.',
            ], 500);
        }
    }




    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
    public function store(StoreAdicionaisProdutoRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(AdicionaisProduto $adicionaisProduto)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(AdicionaisProduto $adicionaisProduto)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAdicionaisProdutoRequest $request, AdicionaisProduto $adicionaisProduto)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AdicionaisProduto $adicionaisProduto)
    {
        //
    }
}
