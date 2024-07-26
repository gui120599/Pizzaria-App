<?php

namespace App\Http\Controllers;

use App\Models\ItensPedido;
use App\Http\Requests\StoreItensPedidoRequest;
use App\Http\Requests\UpdateItensPedidoRequest;
use Illuminate\Http\Request;

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
            'item_pedido_valor_unitario' => $request->input('item_pedido_valor'),
            'item_pedido_desconto' => '0.00',
            'item_pedido_valor' => $request->input('item_pedido_valor')
        ]);

        // Salvar o item de pedido no banco de dados
        $itemPedido->save();

        // Retornar uma resposta de sucesso
        return response()->json(['message' => 'Item de pedido criado com sucesso'], 200);
    }

    /**
     * Display the specified resource.
     */
    public function listarProdutosInseridosNoPedido(Request $request) // Adicione $request como parâmetro
    {
        // Obtenha o ID do pedido da requisição
        $item_pedido_pedido_id = $request->item_pedido_pedido_id;

        // Encontre todos os itens de pedido para o pedido específico que estão no status 'INSERIDO'
        $itensPedidoInseridos = ItensPedido::with('produto') // Carregue o relacionamento 'produto'
            ->where('item_pedido_pedido_id', $item_pedido_pedido_id)
            ->where('item_pedido_status', 'INSERIDO')
            ->get();

        // Verifique se há itens de pedido encontrados
        if ($itensPedidoInseridos->isEmpty()) {
            // Se não houver itens de pedido inseridos, retorne uma resposta vazia ou uma mensagem de erro, conforme necessário
            return response()->json(['message' => 'Nenhum produto inserido encontrado para este pedido'], 200);
        }

        // Retorne os itens de pedido inseridos encontrados
        return response()->json($itensPedidoInseridos, 200);
        //return response()->json($request, 200);
    }

    public function calcularValorTotalPedido(Request $request)
    {
        // Obtenha o ID do pedido da requisição
        $item_pedido_pedido_id = $request->item_pedido_pedido_id;

        // Encontre todos os itens de pedido para o pedido específico que estão no status 'INSERIDO'
        $itensPedidoInseridos = ItensPedido::where('item_pedido_pedido_id', $item_pedido_pedido_id)
            ->where('item_pedido_status', 'INSERIDO')
            ->get();

        // Verifique se há itens de pedido encontrados
        if ($itensPedidoInseridos->isEmpty()) {
            // Se não houver itens de pedido inseridos, retorne uma resposta vazia ou uma mensagem de erro, conforme necessário
            return response()->json(['message' => 'Nenhum produto inserido encontrado para este pedido'], 200);
        }

        // Inicialize o valor total do pedido como 0
        $valorTotalItensPedido = 0.00;
        $valorTotalDescontoPedido = 0.00;
        $valorTotalPedido = 0.00;

        // Itere sobre os itens do pedido e adicione o valor de cada item ao valor total do pedido
        foreach ($itensPedidoInseridos as $item) {
            $valorTotalItensPedido += $item->item_pedido_valor_unitario*$item->item_pedido_quantidade;
            $valorTotalDescontoPedido += $item->item_pedido_desconto;
            $valorTotalPedido += $item->item_pedido_valor;
        }

        // Retorne o valor total do pedido
        return response()->json(['valor_total_itens' => $valorTotalItensPedido, 'valor_total_desconto' => $valorTotalDescontoPedido, 'valor_total_pedido' => $valorTotalPedido], 200);
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
        return response()->json(['message' => 'Atualização de quantidade e valor bem-sucedida'], 200);

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

    public function AtualizarDesconto(UpdateItensPedidoRequest $request)
    {

        // Encontrar o item de pedido pelo ID
        $itemPedido = ItensPedido::findOrFail($request->id);

        // Atualizar os campos do item de pedido
        $itemPedido->update([
            'item_pedido_desconto' => $request->input('item_desconto'),
            'item_pedido_valor' => $request->input('novoValorTotal')
        ]);

        // Retornar uma resposta de sucesso
        return response()->json(['message' => 'Atualização de desconto bem-sucedida'], 200);

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
