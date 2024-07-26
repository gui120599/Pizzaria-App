<?php

namespace App\Http\Controllers;

use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Http\Requests\StoreItensVendaRequest;
use App\Http\Requests\UpdateItensVendaRequest;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use Illuminate\Http\Request;

class ItensVendaController extends Controller
{

    /**
     * Retorna os itens de múltiplas sessões de mesa, unificando produtos repetidos.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\Response
     */
    public function adicionarItensSessaoMesa(Request $request)
    {
        // Recebe os IDs das sessões de mesa do request
        $sessaoMesaIds = $request->input('sessaoMesa', []);

        // Valida se os IDs foram fornecidos
        if (empty($sessaoMesaIds)) {
            return response()->json(['result' => 'IDs de sessões de mesa são necessários.'], 200);
        }

        // Busca as sessões de mesa pelos IDs
        $sessoesMesa = SessaoMesa::with(['pedidos.item_pedido_pedido_id.produto'])
            ->whereIn('id', $sessaoMesaIds)
            ->get();

        // Coleta e agrupa todos os itens de pedido associados às sessões de mesa
        $itensAgrupados = [];
        foreach ($sessoesMesa as $sessaoMesa) {
            foreach ($sessaoMesa->pedidos as $pedido) {
                foreach ($pedido->item_pedido_pedido_id as $itemPedido) {
                    $produtoId = $itemPedido->produto->id;

                    if (!isset($itensAgrupados[$produtoId])) {
                        $itensAgrupados[$produtoId] = [
                            'produto' => $itemPedido->produto,
                            'quantidade_total' => 0,
                            'valor_total' => 0,
                            'desconto_total' => 0
                        ];
                    }

                    $itensAgrupados[$produtoId]['quantidade_total'] += $itemPedido->item_pedido_quantidade;
                    $itensAgrupados[$produtoId]['valor_total'] += $itemPedido->item_pedido_valor;
                    $itensAgrupados[$produtoId]['desconto_total'] += $itemPedido->item_pedido_desconto;
                }
            }
        }

        // Converte os itens agrupados para um array de objetos
        $result = [];
        foreach ($itensAgrupados as $item) {
            $result[] = $item;
        }

        // Retorna os itens como resposta JSON
        return response()->json(['result' => $result], 200);
    }

    public function adicionarItensPedido(Request $request)
    {
        // Recebe os IDs dos pedidos e o ID da venda do request
        $pedidoIds = $request->input('pedido_id');
        $vendaId = $request->input('venda_id');
        return response()->json(['pedido' => $pedidoIds],200);

        

        /*// Valida se os IDs dos pedidos e da venda foram fornecidos
        if (empty($pedidoIds) || empty($vendaId)) {
            return response()->json(['result' => 'IDs dos pedidos e ID da venda são necessários.'], 200);
        }

        // Busca os pedidos pelos IDs
        $pedidos = Pedido::with(['item_pedido_pedido_id.produto'])
            ->whereIn('id', $pedidoIds)
            ->get();

        // Coleta e agrupa todos os itens de pedido associados aos pedidos
        $itensAgrupados = [];
        foreach ($pedidos as $pedido) {
            foreach ($pedido->item_pedido_pedido_id as $itemPedido) {
                $produtoId = $itemPedido->produto->id;

                if (!isset($itensAgrupados[$produtoId])) {
                    $itensAgrupados[$produtoId] = [
                        'produto' => $itemPedido->produto,
                        'quantidade_total' => 0,
                        'valor_total' => 0,
                        'desconto_total' => 0
                    ];
                }

                $itensAgrupados[$produtoId]['quantidade_total'] += $itemPedido->item_pedido_quantidade;
                $itensAgrupados[$produtoId]['valor_total'] += $itemPedido->item_pedido_valor;
                $itensAgrupados[$produtoId]['desconto_total'] += $itemPedido->item_pedido_desconto;
            }
        }

        // Converte os itens agrupados para um array de objetos e adiciona ou atualiza na tabela de vendas
        foreach ($itensAgrupados as $produtoId => $item) {
            // Verifica se o produto já existe na venda
            $itemVenda = ItensVenda::where('item_venda_venda_id', $vendaId)
                ->where('item_venda_produto_id', $produtoId)
                ->first();

            if ($itemVenda) {
                // Atualiza a quantidade e os valores se o item já existir na venda
                $itemVenda->update([
                    'item_venda_quantidade' => $itemVenda->item_venda_quantidade + $item['quantidade_total'],
                    'item_venda_valor' => $itemVenda->item_venda_valor + $item['valor_total'],
                    'item_venda_desconto' => $itemVenda->item_venda_desconto + $item['desconto_total'],
                ]);
            } else {
                // Cria um novo item de venda se não existir
                ItensVenda::create([
                    'item_venda_venda_id' => $vendaId,
                    'item_venda_produto_id' => $produtoId,
                    'item_venda_quantidade' => $item['quantidade_total'],
                    'item_venda_valor_unitario' => $item['produto']->preco_unitario, // supondo que existe um campo 'preco_unitario' no produto
                    'item_venda_valor' => $item['valor_total'],
                    'item_venda_desconto' => $item['desconto_total'],
                    // Preencha os outros campos conforme necessário
                ]);
            }
        }

        // Retorna os itens como resposta JSON
        return response()->json(['result' => $itensAgrupados], 200);*/
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
    public function store(StoreItensVendaRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(ItensVenda $itensVenda)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ItensVenda $itensVenda)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateItensVendaRequest $request, ItensVenda $itensVenda)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ItensVenda $itensVenda)
    {
        //
    }
}
