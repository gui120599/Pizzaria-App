<?php

namespace App\Http\Controllers;

use App\Models\AdicionaisItemPedido;
use App\Models\ItensPedido;
use App\Models\Produto;
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
        $desconto = (float) $request->input('item_pedido_desconto', 0);

        $itemPedido = new ItensPedido([
            'item_pedido_produto_id' => $request->input('item_pedido_produto_id'),
            'item_pedido_pedido_id' => $request->input('item_pedido_pedido_id'),
            'item_pedido_quantidade' => $request->input('item_pedido_quantidade'),
            'item_pedido_valor_unitario' => $request->input('item_pedido_valor'),
            'item_pedido_desconto' => $desconto,
            'item_pedido_valor' => $request->input('item_pedido_valor')
        ]);

        $itemPedido->save();

        Produto::where('id', $request->input('item_pedido_produto_id'))->increment('produto_qtd_vendas');

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
        $itemPedido = ItensPedido::findOrFail($request->id);
        $adicionaisItemPedido = AdicionaisItemPedido::where('aip_item_pedido_id', $request->id)->get();

        $precoVenda = (float) $itemPedido->produto->produto_preco_venda;
        $precoPromo = (float) ($itemPedido->produto->produto_preco_promocional ?? 0);
        $descontoUnitario = $precoPromo > 0 ? ($precoVenda - $precoPromo) : 0;

        if (!$adicionaisItemPedido->isEmpty()) {
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

            $adicionaisItemPedido = $itemPedido->adicionaisItemPedido()->get();
            $valorTotalAdicionais = $adicionaisItemPedido->sum('aip_valor_total');
            $qtd = (float) $request->input('item_pedido_quantidade');

            if ($qtd == 0.5) {
                $itemPedido->update([
                    'item_pedido_quantidade' => $qtd,
                    'item_pedido_valor_adicionais' => $valorTotalAdicionais * 2,
                    'item_pedido_valor_unitario' => ($precoVenda * $qtd) + $valorTotalAdicionais,
                    'item_pedido_desconto' => round($descontoUnitario * $qtd, 2),
                    'item_pedido_valor' => ($precoVenda * $qtd) + $valorTotalAdicionais,
                ]);
            } else {
                $itemPedido->update([
                    'item_pedido_quantidade' => $qtd,
                    'item_pedido_valor_adicionais' => $valorTotalAdicionais,
                    'item_pedido_valor_unitario' => (($precoVenda * $qtd) + $valorTotalAdicionais) / $qtd,
                    'item_pedido_desconto' => round($descontoUnitario * $qtd, 2),
                    'item_pedido_valor' => ($precoVenda * $qtd) + $valorTotalAdicionais,
                ]);
            }

            $itemPedido->load('adicionaisItemPedido');

            return response()->json(['message' => 'Atualização de quantidade e valor bem-sucedida 1', 'itemPedido' => $itemPedido], 200);
        }

        $qtd = (float) $request->input('item_pedido_quantidade');
        $itemPedido->update([
            'item_pedido_quantidade' => $qtd,
            'item_pedido_valor_unitario' => $request->input('item_pedido_valor_unitario'),
            'item_pedido_desconto' => round($descontoUnitario * $qtd, 2),
            'item_pedido_valor' => $request->input('item_pedido_valor'),
        ]);

        $itemPedido->load('adicionaisItemPedido');

        return response()->json(['message' => 'Atualização de quantidade e valor bem-sucedida 2!', 'itemPedido' => $itemPedido], 200);
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
