<?php

namespace App\Http\Controllers;

use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Http\Requests\StoreItensVendaRequest;
use App\Http\Requests\UpdateItensVendaRequest;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoMesa;
use App\Models\Venda;
use Carbon\Carbon;
use App\Services\VendaService;
use Illuminate\Http\Request;

class ItensVendaController extends Controller
{
    protected $vendaService;

    public function __construct(VendaService $vendaService)
    {
        $this->vendaService = $vendaService;
    }

    /**
     * Retorna os itens de múltiplas sessões de mesa, unificando produtos repetidos.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\Response
     */
    public function adicionarItensSessaoMesa(Request $request)
    {
        // Recebe os IDs da sessão da mesa e da venda do request
        $sessaoMesa_id = $request->input('sessaoMesa_id');
        $venda_id = $request->input('venda_id');

        // Obter a venda
        $venda = Venda::find($venda_id);

        if (!$venda) {
            return response()->json(['error' => 'Venda não encontrada'], 404);
        }

        // Obter todos os pedidos da sessão de mesa fornecida
        $pedidos = Pedido::where('pedido_sessao_mesa_id', $sessaoMesa_id)->get();

        foreach ($pedidos as $pedido) {
            // Obter todos os itens dos pedidos fornecidos
            $itensPedido = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
                ->where('item_pedido_status', 'INSERIDO')
                ->get();

            foreach ($itensPedido as $item) {
                $itemVenda = ItensVenda::where('item_venda_produto_id', $item->item_pedido_produto_id)
                    ->where('item_venda_venda_id', $venda_id)
                    ->first();

                if ($itemVenda) {
                    // Se o item já existe na venda, atualizar os valores
                    $itemVenda->item_venda_quantidade += $item->item_pedido_quantidade;
                    $itemVenda->item_venda_quantidade_tributavel += $item->item_pedido_quantidade;
                    $itemVenda->item_venda_valor_unitario += $item->produto->produto_preco_venda * $item->item_pedido_quantidade;
                    $itemVenda->item_venda_valor_unitario_tributavel += $item->produto->produto_preco_venda * $item->item_pedido_quantidade;
                    $itemVenda->item_venda_desconto += $item->item_pedido_desconto;
                    $itemVenda->item_venda_valor += (($item->produto->produto_preco_venda * $item->item_pedido_quantidade)-$item->item_pedido_desconto);

                    $itemVenda->item_venda_valor_base_calculo += (($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto);

                    $itemVenda->item_venda_valor_icms += ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_icms) / 100;
                    $itemVenda->item_venda_valor_pis += ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_pis) / 100;
                    $itemVenda->item_venda_valor_cofins += ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_cofins) / 100;
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
                        'item_venda_valor_unitario' => $item->produto->produto_preco_venda,
                        'item_venda_desconto' => $item->item_pedido_desconto,
                        'item_venda_valor' => (($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto),
                        'item_venda_status' => 'INSERIDO',
                        //Impostos
                        'item_venda_quantidade_tributavel' => $item->item_pedido_quantidade,
                        'item_venda_valor_unitario_tributavel' => $item->produto->produto_preco_venda,
                        'item_venda_valor_base_calculo' => (($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto),
                        'item_venda_valor_icms' => ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_icms) / 100,
                        'item_venda_valor_pis' => ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_pis) / 100,
                        'item_venda_valor_cofins' => ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_cofins) / 100,
                    ]);
                }
            }
        }

        // Atualizar valores da venda
        $this->vendaService->atualizarValoresdaVenda($request->input('venda_id'));

        return response()->json(['success' => 'Itens adicionados e pedidos finalizados'], 200);
    }

    public function removerItensSessaoMesa(Request $request)
    {
        // Recebe os IDs da sessão da mesa e da venda do request
        $sessaoMesa_id = $request->input('sessaoMesa_id');
        $venda_id = $request->input('venda_id');

        // Obter a venda
        $venda = Venda::find($venda_id);

        if (!$venda) {
            return response()->json(['error' => 'Venda não encontrada'], 404);
        }

        // Obter todos os pedidos da sessão de mesa fornecida
        $pedidos = Pedido::where('pedido_sessao_mesa_id', $sessaoMesa_id)->get();

        foreach ($pedidos as $pedido) {

            // Obter todos os itens dos pedidos fornecidos
            $itensPedido = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
                ->where('item_pedido_status', 'INSERIDO')
                ->get();

            foreach ($itensPedido as $item) {
                $itemVenda = ItensVenda::where('item_venda_produto_id', $item->item_pedido_produto_id)
                    ->where('item_venda_venda_id', $venda_id)
                    ->first();

                if ($itemVenda) {
                    // Reduzir a quantidade do item na venda
                    $itemVenda->item_venda_quantidade -= $item->item_pedido_quantidade;
                    $itemVenda->item_venda_quantidade_tributavel -= $item->item_pedido_quantidade;
                    $itemVenda->item_venda_valor_unitario -= $item->produto->produto_preco_venda * $item->item_pedido_quantidade;
                    $itemVenda->item_venda_valor_unitario_tributavel -= $item->produto->produto_preco_venda * $item->item_pedido_quantidade;
                    $itemVenda->item_venda_desconto -= $item->item_pedido_desconto;
                    $itemVenda->item_venda_valor -= (($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_quantidade);

                    $itemVenda->item_venda_valor_base_calculo -= (($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto);

                    $itemVenda->item_venda_valor_icms -= ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_icms) / 100;
                    $itemVenda->item_venda_valor_pis -= ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_pis) / 100;
                    $itemVenda->item_venda_valor_cofins -= ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_cofins) / 100;

                    // Verificar se a quantidade é menor ou igual a zero para remover o item
                    if ($itemVenda->item_venda_quantidade <= 0) {
                        $itemVenda->delete();
                    } else {
                        $itemVenda->save();
                    }

                   
                }
            }
        }

         // Atualizar valores da venda
        $this->vendaService->atualizarValoresdaVenda($request->input('venda_id'));

        return response()->json(['success' => 'Itens removidos e pedidos atualizados para ENTREGUE'], 200);
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
                $itemVenda->item_venda_valor += (($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto);

                $itemVenda->item_venda_valor_base_calculo += (($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto);

                $itemVenda->item_venda_valor_icms += ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_icms) / 100;
                $itemVenda->item_venda_valor_pis += ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_pis) / 100;
                $itemVenda->item_venda_valor_cofins += ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_cofins) / 100;
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
                    'item_venda_valor_unitario' => $item->produto->produto_preco_venda,
                    'item_venda_desconto' => $item->item_pedido_desconto,
                    'item_venda_valor' => (($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto),
                    'item_venda_status' => 'INSERIDO',
                    //Impostos
                    'item_venda_quantidade_tributavel' => $item->item_pedido_quantidade,
                    'item_venda_valor_unitario_tributavel' => $item->produto->produto_preco_venda,
                    'item_venda_valor_base_calculo' => (($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto),
                    'item_venda_valor_icms' => ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_icms) / 100,
                    'item_venda_valor_pis' => ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_pis) / 100,
                    'item_venda_valor_cofins' => ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_cofins) / 100,
                ]);

            }
        }

        // Atualizar valores da venda
        $this->vendaService->atualizarValoresdaVenda($request->input('venda_id'));


        return response()->json(['success' => 'Adicionado']);
    }

    public function removerItensPedido(Request $request)
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
                // Reduzir a quantidade do item na venda
                $itemVenda->item_venda_quantidade -= $item->item_pedido_quantidade;
                $itemVenda->item_venda_quantidade_tributavel -= $item->item_pedido_quantidade;
                $itemVenda->item_venda_valor_unitario -= $item->produto->produto_preco_venda;
                $itemVenda->item_venda_valor_unitario_tributavel -= $item->produto->produto_preco_venda;
                $itemVenda->item_venda_desconto -= $item->item_pedido_desconto;
                $itemVenda->item_venda_valor -= (($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto);

                $itemVenda->item_venda_valor_base_calculo -= (($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto);

                $itemVenda->item_venda_valor_icms -= ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_icms) / 100;
                $itemVenda->item_venda_valor_pis -= ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_pis) / 100;
                $itemVenda->item_venda_valor_cofins -= ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto) * $item->produto->produto_valor_percentual_cofins) / 100;

                // Verificar se a quantidade é menor ou igual a zero para remover o item
                if ($itemVenda->item_venda_quantidade <= 0) {
                    $itemVenda->delete();
                } else {
                    $itemVenda->save();
                }

            } else {
                return response()->json(['success' => 'Item Não encontrado'], 200);
            }
        }

         // Atualizar valores da venda
        $this->vendaService->atualizarValoresdaVenda($request->input('venda_id'));

        return response()->json(['success' => 'Itens removidos e pedido atualizado para ENTREGUE'], 200);
    }

    public function adicionarProduto(Request $request)
    {
        // Recebe os IDs dos pedidos e o ID da venda do request
        $produto_id = $request->input('produto_id');
        $venda_id = $request->input('venda_id');;

        // Obter a venda
        $venda = Venda::find($venda_id);

        if (!$venda) {
            return response()->json(['error' => 'Venda não encontrada'], 404);
        }

        $itemVenda = ItensVenda::where('item_venda_produto_id', $produto_id)
            ->where('item_venda_venda_id', $venda_id)
            ->first();

        if ($itemVenda) {
            // Se o item já existe na venda
            $itemVenda->item_venda_quantidade += 1;
            $itemVenda->item_venda_quantidade_tributavel += 1;
            $itemVenda->item_venda_valor += $itemVenda->produto->produto_preco_venda;

            $itemVenda->item_venda_valor_base_calculo += $itemVenda->produto->produto_preco_venda;

            $itemVenda->item_venda_valor_icms += ($itemVenda->produto->produto_preco_venda * $itemVenda->produto->produto_valor_percentual_icms) / 100;
            $itemVenda->item_venda_valor_pis += ($itemVenda->produto->produto_preco_venda * $itemVenda->produto->produto_valor_percentual_pis) / 100;
            $itemVenda->item_venda_valor_cofins += ($itemVenda->produto->produto_preco_venda * $itemVenda->produto->produto_valor_percentual_cofins) / 100;
            $itemVenda->save();

        } else {
            $produto = Produto::find($produto_id);
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
                'item_venda_produto_id' => $produto_id,
                'item_venda_quantidade' => 1,
                'item_venda_valor_unitario' => $produto->produto_preco_venda,
                'item_venda_valor' => $produto->produto_preco_venda,
                'item_venda_status' => 'INSERIDO',
                //Impostos
                'item_venda_quantidade_tributavel' => 1,
                'item_venda_valor_unitario_tributavel' => $produto->produto_preco_venda,
                'item_venda_valor_base_calculo' => $produto->produto_preco_venda,
                'item_venda_valor_icms' => ($produto->produto_preco_venda * $produto->produto_valor_percentual_icms) / 100,
                'item_venda_valor_pis' => ($produto->produto_preco_venda * $produto->produto_valor_percentual_pis) / 100,
                'item_venda_valor_cofins' => ($produto->produto_preco_venda * $produto->produto_valor_percentual_cofins) / 100,
            ]);

        }

         // Atualizar valores da venda
        $this->vendaService->atualizarValoresdaVenda($request->input('venda_id'));
        return response()->json(['success' => 'Adicionado']);
    }

    public function removerProduto(Request $request)
    {
        // Recebe os IDs dos pedidos e o ID da venda do request
        $item_id = $request->input('item_id');
        $venda_id = $request->input('venda_id');

        // Obter a venda
        $venda = Venda::find($venda_id);

        if (!$venda) {
            return response()->json(['error' => 'Venda não encontrada'], 404);
        }

        $itemVenda = ItensVenda::find($item_id);

        if ($itemVenda) {
            // Se o item já existe na venda
            $itemVenda->delete();

        } else {
            return response()->json(['success' => 'Item Não encontrado'], 200);
        }

         // Atualizar valores da venda
        $this->vendaService->atualizarValoresdaVenda($request->input('venda_id'));

        return response()->json(['success' => 'Removido']);
    }

    public function atualizarDescontoItemVenda(Request $request)
    {   
        // Recebe os IDs dos pedidos e o ID da venda do request
        $item_id = $request->input('item_id');
        $venda_id = $request->input('venda_id');
        $item_venda_desconto = $request->input('item_desconto');

        // Obter a venda
        $venda = Venda::find($venda_id);
        if (!$venda) {
            return response()->json(['error' => 'Venda não encontrada'], 200);
        }

        // Obter o item da venda
        $itemVenda = ItensVenda::find($item_id);
        if (!$itemVenda) {
            return response()->json(['error' => 'Item não encontrado'], 200);
        }


        // Atualizar os valores do item com o novo desconto
        $itemVenda->item_venda_valor_base_calculo = (($itemVenda->produto->produto_preco_venda * $itemVenda->item_venda_quantidade) - $item_venda_desconto);
        $itemVenda->item_venda_desconto = $item_venda_desconto;
        $itemVenda->item_venda_valor = $itemVenda->item_venda_valor_base_calculo;
        $itemVenda->item_venda_valor_icms = ($itemVenda->item_venda_valor_base_calculo * $itemVenda->produto->produto_valor_percentual_icms) / 100;
        $itemVenda->item_venda_valor_pis = ($itemVenda->item_venda_valor_base_calculo * $itemVenda->produto->produto_valor_percentual_pis) / 100;
        $itemVenda->item_venda_valor_cofins = ($itemVenda->item_venda_valor_base_calculo * $itemVenda->produto->produto_valor_percentual_cofins) / 100;

        $itemVenda->save();

        // Atualizar valores da venda
        $this->vendaService->atualizarValoresdaVenda($request->input('venda_id'));

        return response()->json(['success' => 'Valor de desconto atualizado!']);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $venda_id = 18;
        // Atualizar valores da venda
        $this->vendaService->atualizarValoresdaVenda($venda_id);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function listarItensVenda(Request $request)
    {
        $venda_id = $request->input('venda_id');
        $itensVenda = ItensVenda::with('produto')
            ->where('item_venda_venda_id', $venda_id)
            ->where('item_venda_status', 'INSERIDO')->get();

        return response()->json($itensVenda, 200);
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
