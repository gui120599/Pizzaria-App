<?php

namespace App\Http\Controllers;

use App\Models\AdicionaisItemPedido;
use App\Models\ItensPedido;
use App\Http\Requests\StoreItensPedidoRequest;
use App\Http\Requests\UpdateItensPedidoRequest;
use Illuminate\Http\Request;

class ItensPedidoController extends Controller
{
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
        $itensPedidoInseridos = ItensPedido::with(['produto.categoria', 'produto.ap_produto_id.adicional', 'adicionaisItemPedido.adicional']) // Carregue o relacionamento 'produto'
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

    /**
     * Summary of calcularValorTotalPedido
     * @param \Illuminate\Http\Request $request
     * @return mixed|\Illuminate\Http\JsonResponse
     */
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
            $valorTotalItensPedido += $item->item_pedido_valor;
            $valorTotalDescontoPedido += $item->item_pedido_desconto;
            $valorTotalPedido += $item->item_pedido_valor - $item->item_pedido_desconto;
        }

        // Retorne o valor total do pedido
        return response()->json(['valor_total_itens' => $valorTotalItensPedido, 'valor_total_desconto' => $valorTotalDescontoPedido, 'valor_total_pedido' => $valorTotalPedido], 200);
    }


    /**
     * Atualiza o valor do item via Java Script no formato JSON
     */
    public function AtualizarQtdValor(UpdateItensPedidoRequest $request)
    {

        // Encontrar o item de pedido pelo ID
        $itemPedido = ItensPedido::findOrFail($request->id);
        $adicionaisItemPedido = AdicionaisItemPedido::where('aip_item_pedido_id', $request->id)->get();

        if (!$adicionaisItemPedido->isEmpty()) {

            // Atualizar a quantidade dos adicionais para coincidir com a quantidade do item do pedido
            $novaQuantidade = $request->input('item_pedido_quantidade');
            if ($novaQuantidade == 0.5) {
                $novaQuantidade = 1;
            }

            foreach ($adicionaisItemPedido as $adicional) {
                $adicional->update([
                    'aip_quantidade' => $novaQuantidade,
                    'aip_valor_total' => $adicional->aip_valor_unitario * $novaQuantidade,
                ]);
            }

            // Recarregar o array com os valores atualizados
            $adicionaisItemPedido = $itemPedido->adicionaisItemPedido()->get();

            // Somar os valores totais dos adicionais, se existirem
            $valorTotalAdicionais = $adicionaisItemPedido->sum('aip_valor_total');

            if ($request->input('item_pedido_quantidade') == 0.5) {
                // Atualizar os campos do item de pedido
                $itemPedido->update([
                    'item_pedido_quantidade' => $request->input('item_pedido_quantidade'),
                    'item_pedido_valor_adicionais' => $valorTotalAdicionais * 2,
                    'item_pedido_valor_unitario' => (($itemPedido->produto->produto_preco_venda * $request->input('item_pedido_quantidade')) + $valorTotalAdicionais),
                    'item_pedido_valor' => ($itemPedido->produto->produto_preco_venda * $request->input('item_pedido_quantidade')) + $valorTotalAdicionais,
                ]);
            } else {
                // Atualizar os campos do item de pedido
                $itemPedido->update([
                    'item_pedido_quantidade' => $request->input('item_pedido_quantidade'),
                    'item_pedido_valor_adicionais' => $valorTotalAdicionais,
                    'item_pedido_valor_unitario' => (($itemPedido->produto->produto_preco_venda * $request->input('item_pedido_quantidade')) + $valorTotalAdicionais) / $request->input('item_pedido_quantidade'),
                    'item_pedido_valor' => ($itemPedido->produto->produto_preco_venda * $request->input('item_pedido_quantidade')) + $valorTotalAdicionais,
                ]);
            }

            $itemPedido->load('adicionaisItemPedido');

            // Retornar uma resposta de sucesso
            return response()->json(['message' => 'Atualização de quantidade e valor bem-sucedida 1', 'itemPedido' => $itemPedido], 200);
        }



        // Atualizar os campos do item de pedido
        $itemPedido->update([
            'item_pedido_quantidade' => $request->input('item_pedido_quantidade'),
            'item_pedido_valor_unitario' => $request->input('item_pedido_valor_unitario'),
            'item_pedido_valor' => $request->input('item_pedido_valor'),
        ]);

        $itemPedido->load('adicionaisItemPedido');

        // Retornar uma resposta de sucesso
        return response()->json(['message' => 'Atualização de quantidade e valor bem-sucedida 2!', 'itemPedido' => $itemPedido], 200);

        //return response()->json($itemPedido);
    }


    /**
     * Atualiza o item adicionando a observação via Java Script no formato JSON
     */
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


    /**
     * Atualiza o item adicionando o valor de desconto via Java Script no formato JSON
     */
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

    /**
     * Atualiza o item do pedido adicionando o valor 'REMOVIDO' no campo de status
     */
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
}
