<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAdicionaisItemPedidoRequest;
use App\Http\Requests\UpdateAdicionaisItemPedidoRequest;
use App\Models\AdicionaisItemPedido;
use App\Models\ItensPedido;

class AdicionaisItemPedidoController extends Controller
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
    public function store(StoreAdicionaisItemPedidoRequest $request)
    {
        // Encontre o registro existente
        $adicionalItemPedido = AdicionaisItemPedido::where('aip_adicional_id', $request->input('adicionalId'))
            ->where('aip_item_pedido_id', $request->input('item_pedidoId'))
            ->first();

        // Busque o item do pedido
        $itemPedido = ItensPedido::find($request->input('item_pedidoId'));
        if (! $itemPedido) {
            return response()->json(['message' => 'Item do pedido não encontrado.'], 404);
        }

        // Calcular o valor total baseado na quantidade
        $quantidade = $request->input('quantidade');
        $valor_unitario = $request->input('valor_unitario');
        $valor_total = $quantidade * $valor_unitario;

        if ($adicionalItemPedido) {
            if ($quantidade == 0) {
                // Exclua o adicional se a quantidade for 0
                $adicionalItemPedido->delete();
                if ($itemPedido->item_pedido_quantidade == 0.5) {

                    // Atualize o valor do item do pedido
                    $itemPedido->update([
                        'item_pedido_valor_adicionais' => $itemPedido->item_pedido_valor_adicionais - $adicionalItemPedido->aip_valor_total,
                        'item_pedido_valor_unitario' => $itemPedido->item_pedido_valor - $adicionalItemPedido->aip_valor_total / 2,
                        'item_pedido_valor' => $itemPedido->item_pedido_valor - $adicionalItemPedido->aip_valor_total / 2,
                    ]);
                } else {
                    // Atualize o valor do item do pedido
                    $itemPedido->update([
                        'item_pedido_valor_adicionais' => $itemPedido->item_pedido_valor_adicionais - $adicionalItemPedido->aip_valor_total,
                        'item_pedido_valor_unitario' => ($itemPedido->item_pedido_valor - $adicionalItemPedido->aip_valor_total) / $itemPedido->item_pedido_quantidade,
                        'item_pedido_valor' => $itemPedido->item_pedido_valor - $adicionalItemPedido->aip_valor_total,
                    ]);
                }

                $itemPedido->load('adicionaisItemPedido');

                return response()->json(['message' => 'Adicional excluído ao Item com sucesso!', 'itemPedido' => $itemPedido], 200);
            }

            if ($itemPedido->item_pedido_quantidade == 0.5) {
                // Atualize o adicional existente
                $adicionalItemPedido->update([
                    'aip_quantidade' => $quantidade,
                    'aip_valor_unitario' => $valor_unitario,
                    'aip_valor_total' => $valor_total * 2,
                ]);

                // Recarregar o array com os valores atualizados
                $adicionaisItemPedido = $itemPedido->adicionaisItemPedido()->get();

                // Somar os valores totais dos adicionais, se existirem
                $valorTotalAdicionais = $adicionaisItemPedido->sum('aip_valor_total');

                // Atualize o valor do item do pedido
                $itemPedido->update([
                    'item_pedido_valor_adicionais' => $valorTotalAdicionais,
                    'item_pedido_valor_unitario' => ($itemPedido->produto->produto_preco_venda * $itemPedido->item_pedido_quantidade) + $valorTotalAdicionais / 2,
                    'item_pedido_valor' => round((($itemPedido->produto->produto_preco_venda * $itemPedido->item_pedido_quantidade) + $valorTotalAdicionais / 2) - $itemPedido->item_pedido_desconto, 2),
                ]);
            } else {
                // Atualize o adicional existente
                $adicionalItemPedido->update([
                    'aip_quantidade' => $quantidade,
                    'aip_valor_unitario' => $valor_unitario,
                    'aip_valor_total' => $valor_total,
                ]);

                // Recarregar o array com os valores atualizados
                $adicionaisItemPedido = $itemPedido->adicionaisItemPedido()->get();

                // Somar os valores totais dos adicionais, se existirem
                $valorTotalAdicionais = $adicionaisItemPedido->sum('aip_valor_total');
                // Atualize o valor do item do pedido
                $itemPedido->update([
                    'item_pedido_valor_adicionais' => $valorTotalAdicionais,
                    'item_pedido_valor_unitario' => (($itemPedido->produto->produto_preco_venda * $itemPedido->item_pedido_quantidade) + $valorTotalAdicionais) / $itemPedido->item_pedido_quantidade,
                    'item_pedido_valor' => round((($itemPedido->produto->produto_preco_venda * $itemPedido->item_pedido_quantidade) + $valorTotalAdicionais) - $itemPedido->item_pedido_desconto, 2),
                ]);
            }

            $itemPedido->load('adicionaisItemPedido');

            return response()->json(['message' => 'Adicional atualizado ao Item com sucesso!', 'itemPedido' => $itemPedido], 200);
        } else {

            if ($itemPedido->item_pedido_quantidade == 0.5) {
                // Crie um novo adicional
                AdicionaisItemPedido::create([
                    'aip_adicional_id' => $request->input('adicionalId'),
                    'aip_item_pedido_id' => $request->input('item_pedidoId'),
                    'aip_quantidade' => $quantidade,
                    'aip_valor_unitario' => $valor_unitario,
                    'aip_valor_total' => $valor_total * 2,
                ]);
                // Atualize o valor do item do pedido
                $itemPedido->update([
                    'item_pedido_valor_adicionais' => $itemPedido->item_pedido_valor_adicionais + $valor_total * 2,
                    'item_pedido_valor_unitario' => $itemPedido->item_pedido_valor + $valor_total,
                    'item_pedido_valor' => $itemPedido->item_pedido_valor + $valor_total,
                ]);
            } else {
                // Crie um novo adicional
                AdicionaisItemPedido::create([
                    'aip_adicional_id' => $request->input('adicionalId'),
                    'aip_item_pedido_id' => $request->input('item_pedidoId'),
                    'aip_quantidade' => $quantidade,
                    'aip_valor_unitario' => $valor_unitario,
                    'aip_valor_total' => $valor_total,
                ]);
                // Atualize o valor do item do pedido
                $itemPedido->update([
                    'item_pedido_valor_adicionais' => $itemPedido->item_pedido_valor_adicionais + $valor_total,
                    'item_pedido_valor_unitario' => ($itemPedido->item_pedido_valor + $valor_total) / $itemPedido->item_pedido_quantidade,
                    'item_pedido_valor' => $itemPedido->item_pedido_valor + $valor_total,
                ]);
            }

            $itemPedido->load('adicionaisItemPedido');

            return response()->json(['message' => 'Adicional adicionado ao Item com sucesso!', 'itemPedido' => $itemPedido], 200);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(AdicionaisItemPedido $adicionaisItemPedido)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(AdicionaisItemPedido $adicionaisItemPedido)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAdicionaisItemPedidoRequest $request, AdicionaisItemPedido $adicionaisItemPedido)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AdicionaisItemPedido $adicionaisItemPedido)
    {
        //
    }
}
