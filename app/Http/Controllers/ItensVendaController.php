<?php

namespace App\Http\Controllers;

use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Http\Requests\StoreItensVendaRequest;
use App\Http\Requests\UpdateItensVendaRequest;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Models\Venda;
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
        $pedido_id = $request->input('pedido_id');
        $venda_id = $request->input('venda_id');

        // Obter a venda
        $venda = Venda::find($venda_id);

        if (!$venda) {
            return response()->json(['error' => 'Venda não encontrada'], 404);
        }

        // Obter todos os itens dos pedidos fornecidos
        $itensPedido = ItensPedido::where('item_pedido_pedido_id', $pedido_id)
            ->where('item_pedido_status', 'INSERIDO')
            ->get();

        foreach ($itensPedido as $item) {
            $itemVenda = ItensVenda::where('item_venda_produto_id', $item->item_pedido_produto_id)
                ->where('item_venda_venda_id', $venda_id)
                ->first();

            if ($itemVenda) {
                // Se o item já existe na venda
                $itemVenda->item_venda_quantidade += $item->item_pedido_quantidade;
                $itemVenda->item_venda_quantidade_tributavel += $item->item_pedido_quantidade;
                $itemVenda->item_venda_valor_unitario += $item->produto->produto_preco_venda;
                $itemVenda->item_venda_valor_unitario_tributavel += $item->produto->produto_preco_venda;
                $itemVenda->item_venda_desconto += $item->item_pedido_desconto;
                $itemVenda->item_venda_valor += $item->item_pedido_valor;
                $itemVenda->item_venda_base_calculo_icms += $item->produto->produto_valor_percentual_icms;

                $itemVenda->item_venda_valor_icms = ($item->item_pedido_valor * $item->produto->produto_valor_percentual_icms) / 100;
                $itemVenda->save();
            } else {
                // Buscar o último número sequencial da venda
                $lastItem = ItensVenda::where('item_venda_venda_id', $venda_id)
                    ->orderBy('item_numero', 'desc')
                    ->first();

                // Definir o próximo número sequencial
                $nextItemNumber = $lastItem ? $lastItem->item_numero + 1 : 1;

                // Se o item não existe na venda, adicionar o item
                ItensVenda::create([
                    'item_numero' => $nextItemNumber,
                    'item_venda_venda_id' => $venda_id,
                    'item_venda_produto_id' => $item->item_pedido_produto_id,
                    'item_venda_quantidade' => $item->item_pedido_quantidade,
                    'item_venda_quantidade_tributavel' => $item->item_pedido_quantidade,
                    'item_venda_valor_unitario' => $item->produto->produto_preco_venda,
                    'item_venda_valor_unitario_tributavel' => $item->produto->produto_preco_venda,
                    'item_venda_desconto' => $item->item_pedido_desconto,
                    'item_venda_valor' => $item->item_pedido_valor,
                    'item_venda_base_calculo_icms' => $item->produto->produto_valor_percentual_icms,
                    'item_venda_valor_icms' => ($item->item_pedido_valor * $item->produto->produto_valor_percentual_icms) / 100,
                ]);
            }
        }

        return response()->json(['success' => 'Adicionado']);
    }





    /**
     * Display a listing of the resource.
     */
    public function index()
    {

        // Recebe os IDs dos pedidos e o ID da venda do request
        $pedido_id = 47;
        $venda_id = 9;

        // Obter a venda
        $venda = Venda::find($venda_id);

        if (!$venda) {
            return response()->json(['error' => 'Venda não encontrada'], 404);
        }

        // Obter todos os itens dos pedidos fornecidos
        $itensPedido = ItensPedido::where('item_pedido_pedido_id', $pedido_id)
            ->where('item_pedido_status', 'INSERIDO')
            ->get();

        foreach ($itensPedido as $item) {
            $itemVenda = ItensVenda::where('item_venda_produto_id', $item->item_pedido_produto_id)
                ->where('item_venda_venda_id', $venda_id)
                ->first();

            if ($itemVenda) {
                // Se o item já existe na venda
                $itemVenda->item_venda_quantidade += $item->item_pedido_quantidade;
                $itemVenda->item_venda_quantidade_tributavel += $item->item_pedido_quantidade;
                $itemVenda->item_venda_valor_unitario += $item->produto->produto_preco_venda;
                $itemVenda->item_venda_valor_unitario_tributavel += $item->produto->produto_preco_venda;
                $itemVenda->item_venda_desconto += $item->item_pedido_desconto;
                $itemVenda->item_venda_valor += $item->item_pedido_valor;
                $itemVenda->item_venda_base_calculo_icms += $item->produto->produto_valor_percentual_icms;

                $valorICMS = ($itemVenda->item_venda_valor + $item->item_pedido_valor * 100) / $item->produto->produto_valor_percentual_icms;
                $itemVenda->item_venda_valor_icms = $valorICMS;
                $itemVenda->save();
            } else {
                // Buscar o último número sequencial da venda
                $lastItem = ItensVenda::where('item_venda_venda_id', $venda_id)
                    ->orderBy('item_numero', 'desc')
                    ->first();

                // Definir o próximo número sequencial
                $nextItemNumber = $lastItem ? $lastItem->item_numero + 1 : 1;

                // Se o item não existe na venda, adicionar o item
                ItensVenda::create([
                    'item_numero' => $nextItemNumber,
                    'item_venda_venda_id' => $venda_id,
                    'item_venda_produto_id' => $item->item_pedido_produto_id,
                    'item_venda_quantidade' => $item->item_pedido_quantidade,
                    'item_venda_quantidade_tributavel' => $item->item_pedido_quantidade,
                    'item_venda_valor_unitario' => $item->produto->produto_preco_venda,
                    'item_venda_valor_unitario_tributavel' => $item->produto->produto_preco_venda,
                    'item_venda_desconto' => $item->item_pedido_desconto,
                    'item_venda_valor' => $item->item_pedido_valor,
                    'item_venda_base_calculo_icms' => $item->produto->produto_valor_percentual_icms,
                    'item_venda_valor_icms' => ($item->item_pedido_valor * $item->produto->produto_valor_percentual_icms) / 100,
                ]);
            }
        }
        return redirect()->route('dashboard')->with('success', 'Produto criado com sucesso!');
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
