<?php

namespace App\Http\Controllers;

use App\Models\ItensPedido;
use App\Http\Requests\StoreItensPedidoRequest;
use App\Http\Requests\UpdateItensPedidoRequest;

class ItensPedidoController extends Controller
{
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
    public function store(StoreItensPedidoRequest $request)
    {
        // Criar um novo item de pedido com base nos dados recebidos
        $itemPedido = new ItensPedido([
            'item_pedido_produto_id' => $request->input('item_pedido_produto_id'),
            'item_pedido_pedido_id' => $request->input('item_pedido_pedido_id'),
            'item_pedido_quantidade' => $request->input('item_pedido_quantidade'),
            'item_pedido_valor' => $request->input('item_pedido_valor')
        ]);

        // Salvar o item de pedido no banco de dados
        $itemPedido->save();

        // Retornar uma resposta de sucesso
        return response()->json(['message' => 'Item de pedido criado com sucesso'], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(ItensPedido $itensPedido)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ItensPedido $itensPedido)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateItensPedidoRequest $request, ItensPedido $itensPedido)
    {
        //
    }

    public function AtualizarQtdValor(UpdateItensPedidoRequest $request)
    {
    
        // Encontrar o item de pedido pelo ID
        $itemPedido = ItensPedido::findOrFail($request->id);
    
        // Atualizar os campos do item de pedido
        $itemPedido->update([
            'item_pedido_quantidade' => $request->input('item_pedido_quantidade'),
            'item_pedido_valor' => $request->input('item_pedido_valor'),
        ]);
    
        // Retornar uma resposta de sucesso
        return response()->json(['message' => 'Atualização bem-sucedida'], 200);

        //return response()->json($itemPedido);
    }

    public function AtualizarObservacao(UpdateItensPedidoRequest $request)
    {
    
        // Encontrar o item de pedido pelo ID
        $itemPedido = ItensPedido::findOrFail($request->id);
    
        // Atualizar os campos do item de pedido
        $itemPedido->update([
            'item_pedido_observacao' => $request->input('item_pedido_observacao')
        ]);
    
        // Retornar uma resposta de sucesso
        return response()->json(['message' => 'Atualização bem-sucedida'], 200);

        //return response()->json($itemPedido);
    }

    public function RemoverItem(UpdateItensPedidoRequest $request)
    {
    
        // Encontrar o item de pedido pelo ID
        $itemPedido = ItensPedido::findOrFail($request->id);
    
        // Atualizar os campos do item de pedido
        $itemPedido->update([
            'item_pedido_status' => 'REMOVIDO'
        ]);
    
        // Retornar uma resposta de sucesso
        return response()->json(['message' => 'Remoção bem-sucedida'], 200);

        //return response()->json($itemPedido);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ItensPedido $itensPedido)
    {
        //
    }
}
