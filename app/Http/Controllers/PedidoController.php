<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Http\Requests\StorePedidoRequest;
use App\Http\Requests\UpdatePedidoRequest;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\Produto;
use Illuminate\Http\Request;

class PedidoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $pedidos = Pedido::all();
        $clientes = Cliente::all();
        $opcoes_pagamento = OpcoesPagamento::all();
        $opcoes_entregas = OpcoesEntregas::all();
        $produtos = Produto::all();
        $categorias = Categoria::all();
        return view('app.pedido.index',[
            'pedidos' => $pedidos, 
            'clientes' => $clientes, 
            'opcoes_entregas' => $opcoes_entregas, 
            'opcoes_pagamento' => $opcoes_pagamento, 
            'produtos' => $produtos, 
            'categorias' => $categorias
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function iniciarPedido(Request $request)
    {
        // Criar um novo pedido
        $pedido = new Pedido();
        $pedido->pedido_status = 'INICIADO'; // Definir o status do pedido como 'INICIADO'
        $pedido->save();
    
        // Retornar o ID do pedido em formato JSON
        return response()->json(['pedido_id' => $pedido->id]);
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
    public function store(StorePedidoRequest $request)
    {
        dd($request);
    }

    /**
     * Display the specified resource.
     */
    public function show(Pedido $pedido)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Pedido $pedido)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePedidoRequest $request, Pedido $pedido)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Pedido $pedido)
    {
        //
    }
}
