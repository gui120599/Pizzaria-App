<?php

namespace App\Http\Controllers;

use App\Models\AdicionaisItemPedido;
use App\Models\AdicionaisItemVenda;
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
    /*public function adicionarItensSessaoMesa(Request $request)
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
        $pedidos = Pedido::where('pedido_sessao_mesa_id', $sessaoMesa_id)->whereNotIn('pedido_status', ['CANCELADO', 'FINALIZADO'])->get();

        foreach ($pedidos as $pedido) {
            // Obter todos os itens dos pedidos fornecidos
            $itensPedido = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
                ->where('item_pedido_status', 'INSERIDO')
                ->get();

            foreach ($itensPedido as $item) {

                $itemVenda = ItensVenda::where('item_venda_produto_id', $item->item_pedido_produto_id)
                    ->where('item_venda_venda_id', $venda_id)
                    ->where('item_venda_valor_adicionais', '=', 0)
                    ->where('item_venda_quantidade', '<>', 0.5)
                    ->first();

                if ($itemVenda) {
                    // Se o item já existe na venda, atualizar os valores
                    $itemVenda->item_venda_quantidade += $item->item_pedido_quantidade;
                    $itemVenda->item_venda_desconto += $item->item_pedido_desconto;
                    $itemVenda->item_venda_valor += (($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais);

                    $itemVenda->item_venda_quantidade_tributavel += $item->item_pedido_quantidade;
                    $itemVenda->item_venda_valor_base_calculo += (($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais);
                    $itemVenda->item_venda_valor_icms += ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais) * $item->produto->produto_valor_percentual_icms) / 100;
                    $itemVenda->item_venda_valor_pis += ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais) * $item->produto->produto_valor_percentual_pis) / 100;
                    $itemVenda->item_venda_valor_cofins += ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais) * $item->produto->produto_valor_percentual_cofins) / 100;
                    $itemVenda->item_venda_valor_total_tributos += (((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais) * $item->produto->produto_valor_percentual_icms) / 100) + (((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais) * $item->produto->produto_valor_percentual_pis) / 100) + (((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais) * $item->produto->produto_valor_percentual_cofins) / 100);
                    $itemVenda->save();

                    // Verificar se há adicionais no item do pedido
                    $adicionaisPedido = AdicionaisItemPedido::where('aip_item_pedido_id', $item->id)->get();

                    foreach ($adicionaisPedido as $adicionalPedido) {
                        // Verificar se o adicional já existe no item da venda
                        $adicionalVenda = AdicionaisItemVenda::where('aiv_item_venda_id', $itemVenda->id)
                            ->where('aiv_adicional_id', $adicionalPedido->aip_adicional_id)
                            ->first();

                        if ($adicionalVenda) {
                            // Atualizar o adicional existente
                            $adicionalVenda->aiv_quantidade += $adicionalPedido->aip_quantidade;
                            $adicionalVenda->aiv_valor_total += $adicionalPedido->aip_valor_total;
                            $adicionalVenda->save();
                        } else {
                            // Criar um novo adicional para o item da venda
                            AdicionaisItemVenda::create([
                                'aiv_adicional_id' => $adicionalPedido->aip_adicional_id,
                                'aiv_item_venda_id' => $itemVenda->id,
                                'aiv_valor_unitario' => $adicionalPedido->aip_valor_unitario,
                                'aiv_quantidade' => $adicionalPedido->aip_quantidade,
                                'aiv_valor_total' => $adicionalPedido->aip_valor_total,
                            ]);
                        }
                    }
                } else {
                    // Buscar o último número sequencial da venda
                    $lastItem = ItensVenda::where('item_venda_venda_id', $venda_id)
                        ->orderBy('item_numero', 'desc')
                        ->first();

                    // Definir o próximo número sequencial
                    $nextItemNumber = $lastItem ? $lastItem->item_numero + 1 : 1;
                    $quantidade_unitaria = 1;
                    if ($item->item_pedido_quantidade < 1) {
                        $quantidade_unitaria = $item->item_pedido_quantidade;
                    }

                    //Cria uma variavel de valor unitario para economizar lina de codigo
                    $valorUnitario = ($item->produto->produto_preco_venda - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais)/$item->item_pedido_quantidade;
                    $valorTotal = $valorUnitario*$item->item_pedido_quantidade ;

                    // Se o item não existe na venda, adicionar o item
                    $itemVenda = ItensVenda::create([
                        'item_numero' => $nextItemNumber,
                        'item_venda_venda_id' => $venda_id,
                        'item_venda_produto_id' => $item->item_pedido_produto_id,
                        'item_venda_quantidade' => $item->item_pedido_quantidade,
                        'item_venda_valor_unitario' => $valorUnitario,
                        'item_venda_valor_adicionais' => $item->item_pedido_valor_adicionais,
                        'item_venda_desconto' => $item->item_pedido_desconto,
                        'item_venda_valor' => $valorTotal,
                        'item_venda_status' => 'INSERIDO',

                        //Impostos
                        'item_venda_quantidade_tributavel' => $item->item_pedido_quantidade,
                        'item_venda_valor_unitario_tributavel' => $valorUnitario,
                        'item_venda_valor_base_calculo' => $valorTotal,
                        'item_venda_valor_icms' => ($valorTotal * $item->produto->produto_valor_percentual_icms) / 100,
                        'item_venda_valor_pis' => ($valorTotal * $item->produto->produto_valor_percentual_pis) / 100,
                        'item_venda_valor_cofins' => ($valorTotal * $item->produto->produto_valor_percentual_cofins) / 100,
                        'item_venda_valor_total_tributos' => (($valorTotal * $item->produto->produto_valor_percentual_icms) / 100) + (($valorTotal * $item->produto->produto_valor_percentual_pis) / 100) + (($valorTotal * $item->produto->produto_valor_percentual_cofins) / 100),
                    ]);

                    // Verificar se há adicionais para item do pedido
                    $adicionais = AdicionaisItemPedido::where('aip_item_pedido_id', $item->id)->get();
                    $valorAdicionais = $adicionais->sum('aip_valor_total');

                    foreach ($adicionais as $adicional) {
                        // Adicionar o adicional à tabela de AdicionaisItemVenda
                        AdicionaisItemVenda::create([
                            'aiv_adicional_id' => $adicional->aip_adicional_id,
                            'aiv_item_venda_id' => $itemVenda->id, // ID do item na tabela ItensVenda
                            'aiv_valor_unitario' => $adicional->aip_valor_unitario,
                            'aiv_quantidade' => $adicional->aip_quantidade,
                            'aiv_valor_total' => $adicional->aip_valor_total,
                        ]);
                    }
                }
            }
        }

        // Atualizar valores da venda
        $this->vendaService->atualizarValoresdaVenda($request->input('venda_id'));

        return response()->json(['success' => 'Itens adicionados e pedidos finalizados'], 200);
    }*/

    public function adicionarItensSessaoMesa(Request $request)
    {
        $sessaoMesa_id = $request->input('sessaoMesa_id');
        $venda_id = $request->input('venda_id');

        $venda = Venda::find($venda_id);

        if (!$venda) {
            return response()->json(['error' => 'Venda não encontrada'], 404);
        }

        $pedidos = Pedido::where('pedido_sessao_mesa_id', $sessaoMesa_id)
            ->whereNotIn('pedido_status', ['CANCELADO', 'FINALIZADO'])
            ->whereNull('pedido_venda_id')
            ->get();

        foreach ($pedidos as $pedido) {
            $itensPedido = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
                ->where('item_pedido_status', 'INSERIDO')
                ->with('adicionaisItemPedido')
                ->get();

            $this->adicionarItensPedidoNaVenda($venda_id, $itensPedido);
        }

        $this->vendaService->atualizarValoresdaVenda($venda_id);

        return response()->json(['success' => 'Itens adicionados e pedidos finalizados'], 200);
    }

    public function adicionarItensSessaoMesaPorCliente(Request $request)
    {
        $sessaoMesa_id = $request->input('sessaoMesa_id');
        $cliente_id    = $request->input('cliente_id');
        $venda_id      = $request->input('venda_id');

        $venda = Venda::find($venda_id);
        if (!$venda) {
            return response()->json(['error' => 'Venda não encontrada'], 404);
        }

        $pedidos = Pedido::where('pedido_sessao_mesa_id', $sessaoMesa_id)
            ->whereNotIn('pedido_status', ['CANCELADO', 'FINALIZADO'])
            ->whereNull('pedido_venda_id')
            ->get();

        foreach ($pedidos as $pedido) {
            $query = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
                ->where('item_pedido_status', 'INSERIDO')
                ->with('adicionaisItemPedido');

            if ($cliente_id === 'sem_cliente') {
                $query->whereNull('item_pedido_cliente_id');
            } else {
                $query->where('item_pedido_cliente_id', $cliente_id);
            }

            $this->adicionarItensPedidoNaVenda($venda_id, $query->get());
        }

        $this->vendaService->atualizarValoresdaVenda($venda_id);

        return response()->json(['success' => 'Itens do cliente adicionados'], 200);
    }

    public function adicionarItensPorSelecao(Request $request)
    {
        $item_ids = $request->input('item_ids', []);
        $venda_id = $request->input('venda_id');

        $venda = Venda::find($venda_id);
        if (!$venda) {
            return response()->json(['error' => 'Venda não encontrada'], 404);
        }

        $itensPedido = ItensPedido::whereIn('id', $item_ids)
            ->whereNull('item_pedido_venda_id')
            ->where('item_pedido_status', 'INSERIDO')
            ->with('adicionaisItemPedido', 'produto')
            ->get();

        if ($itensPedido->isEmpty()) {
            return response()->json(['warning' => 'Nenhum item disponível para lançar'], 200);
        }

        $this->adicionarItensPedidoNaVenda($venda_id, $itensPedido);

        ItensPedido::whereIn('id', $itensPedido->pluck('id'))
            ->update(['item_pedido_venda_id' => $venda_id]);

        $this->vendaService->atualizarValoresdaVenda($venda_id);

        return response()->json([
            'success'  => 'Itens lançados na venda',
            'cobrados' => $itensPedido->pluck('id'),
        ], 200);
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
                    $itemVenda->item_venda_desconto -= $item->item_pedido_desconto;
                    $itemVenda->item_venda_valor -= $item->item_pedido_valor;

                    $itemVenda->item_venda_quantidade_tributavel -= $item->item_pedido_quantidade;
                    $itemVenda->item_venda_valor_unitario_tributavel -= $item->item_pedido_valor_unitario - ($item->item_pedido_desconto / $item->item_pedido_quantidade);
                    $itemVenda->item_venda_valor_base_calculo -= $item->item_pedido_valor;
                    $itemVenda->item_venda_valor_icms -= ($item->item_pedido_valor * $item->produto->produto_valor_percentual_icms) / 100;
                    $itemVenda->item_venda_valor_pis -= ($item->item_pedido_valor * $item->produto->produto_valor_percentual_pis) / 100;
                    $itemVenda->item_venda_valor_cofins -= ($item->item_pedido_valor * $item->produto->produto_valor_percentual_cofins) / 100;

                    // Verificar se a quantidade é menor ou igual a zero para remover o item
                    if ($itemVenda->item_venda_quantidade <= 0) {
                        // Deletar adicionais associados antes de remover o item
                        AdicionaisItemVenda::where('aiv_item_venda_id', $itemVenda->id)->delete();
                        $itemVenda->delete();
                    } else {
                        // Atualizar adicionais do item
                        $adicionais = AdicionaisItemVenda::where('aiv_item_venda_id', $itemVenda->id)->get();

                        foreach ($adicionais as $adicional) {
                            $adicional->aiv_quantidade -= $item->item_pedido_quantidade;
                            $adicional->aiv_valor_total -= $adicional->aiv_valor_unitario * $item->item_pedido_quantidade;

                            // Remover o adicional se a quantidade for menor ou igual a zero
                            if ($adicional->aiv_quantidade <= 0) {
                                if ($itemVenda->adicionaisItemVenda()) {
                                    $this->removeAdicionais($itemVenda);
                                }
                                $adicional->delete();
                            } else {
                                $adicional->save();
                            }
                        }

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
            ->whereHas('pedido', function ($query) {
                $query->whereNull('pedido_venda_id');
            })
            ->get();


        $this->adicionarItensPedidoNaVenda($venda_id, $itensPedido);


        $this->vendaService->atualizarValoresdaVenda($venda_id);


        return response()->json(['success' => 'Itens adicionados!'], 200);
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
                $itemVenda->item_venda_valor_unitario_tributavel -= $item->item_pedido_valor_unitario;
                $itemVenda->item_venda_desconto -= $item->item_pedido_desconto;
                $itemVenda->item_venda_valor -= $item->item_pedido_valor;

                $itemVenda->item_venda_valor_base_calculo -= $item->item_pedido_valor;

                $itemVenda->item_venda_valor_icms -= ($item->item_pedido_valor * $item->produto->produto_valor_percentual_icms) / 100;
                $itemVenda->item_venda_valor_pis -= ($item->item_pedido_valor * $item->produto->produto_valor_percentual_pis) / 100;
                $itemVenda->item_venda_valor_cofins -= ($item->item_pedido_valor * $item->produto->produto_valor_percentual_cofins) / 100;

                // Verificar se a quantidade é menor ou igual a zero para remover o item
                if ($itemVenda->item_venda_quantidade <= 0) {
                    if ($itemVenda->adicionaisItemVenda()) {
                        $this->removeAdicionais($itemVenda);
                    }
                    $itemVenda->delete();
                } else {

                    $itemVenda->save();
                }
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
            ->where('item_venda_valor_adicionais', '=', 0)
            ->where('item_venda_quantidade', '<>', 0.5)
            ->first();

        if ($itemVenda) {
            // Se o item já existe na venda e não possui adicionais
            $precoVendaExistente = (float) $itemVenda->produto->produto_preco_venda;
            $precoPromoExistente = (float) ($itemVenda->produto->produto_preco_promocional ?? 0);
            $precoEfetivoExistente = $precoPromoExistente > 0 ? $precoPromoExistente : $precoVendaExistente;
            $descontoUnitarioExistente = $precoEfetivoExistente < $precoVendaExistente ? ($precoVendaExistente - $precoEfetivoExistente) : 0;

            $itemVenda->item_venda_quantidade += 1;
            $itemVenda->item_venda_desconto += $descontoUnitarioExistente;
            $itemVenda->item_venda_valor += $precoEfetivoExistente;

            $itemVenda->item_venda_quantidade_tributavel += 1;
            $itemVenda->item_venda_valor_base_calculo += $precoEfetivoExistente;
            $itemVenda->item_venda_valor_icms += ($precoEfetivoExistente * $itemVenda->produto->produto_valor_percentual_icms) / 100;
            $itemVenda->item_venda_valor_pis += ($precoEfetivoExistente * $itemVenda->produto->produto_valor_percentual_pis) / 100;
            $itemVenda->item_venda_valor_cofins += ($precoEfetivoExistente * $itemVenda->produto->produto_valor_percentual_cofins) / 100;
            $itemVenda->item_venda_valor_total_tributos += (($precoEfetivoExistente * $itemVenda->produto->produto_valor_percentual_icms) / 100) + (($precoEfetivoExistente * $itemVenda->produto->produto_valor_percentual_pis) / 100) + (($precoEfetivoExistente * $itemVenda->produto->produto_valor_percentual_cofins) / 100);
            $itemVenda->save();
            Produto::where('id', $produto_id)->increment('produto_qtd_vendas');
        } else {
            $produto = Produto::find($produto_id);

            $precoVenda = (float) $produto->produto_preco_venda;
            $precoPromo = (float) ($produto->produto_preco_promocional ?? 0);
            $precoEfetivo = $precoPromo > 0 ? $precoPromo : $precoVenda;
            $descontoUnitario = $precoEfetivo < $precoVenda ? ($precoVenda - $precoEfetivo) : 0;

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
                'item_venda_valor_unitario' => $precoEfetivo,
                'item_venda_desconto' => $descontoUnitario,
                'item_venda_valor' => $precoEfetivo,
                'item_venda_status' => 'INSERIDO',
                //Impostos
                'item_venda_quantidade_tributavel' => 1,
                'item_venda_valor_unitario_tributavel' => $precoEfetivo,
                'item_venda_valor_base_calculo' => $precoEfetivo,
                'item_venda_valor_icms' => ($precoEfetivo * $produto->produto_valor_percentual_icms) / 100,
                'item_venda_valor_pis' => ($precoEfetivo * $produto->produto_valor_percentual_pis) / 100,
                'item_venda_valor_cofins' => ($precoEfetivo * $produto->produto_valor_percentual_cofins) / 100,
                'item_venda_valor_total_tributos' => (($precoEfetivo * $produto->produto_valor_percentual_icms) / 100) + (($precoEfetivo * $produto->produto_valor_percentual_pis) / 100) + (($precoEfetivo * $produto->produto_valor_percentual_cofins) / 100),
            ]);
            $produto->increment('produto_qtd_vendas');
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
            if ($itemVenda->adicionaisItemVenda()) {
                $this->removeAdicionais($itemVenda);
            }
            // Se o item já existe na venda
            $itemVenda->delete();
        } else {
            return response()->json(['success' => 'Item Não encontrado'], 200);
        }

        // Atualizar valores da venda
        $this->vendaService->atualizarValoresdaVenda($request->input('venda_id'));

        return response()->json(['success' => 'Removido']);
    }

    public function atualizarQtdValorItemVenda(Request $request)
    {
        $item_id  = $request->input('item_id');
        $venda_id = $request->input('venda_id');
        $novaQtd  = (float) $request->input('item_venda_quantidade');

        $itemVenda = ItensVenda::find($item_id);
        if (! $itemVenda) {
            return response()->json(['error' => 'Item não encontrado'], 200);
        }

        $precoBase = ($itemVenda->produto->produto_preco_promocional > 0 && $itemVenda->produto->produto_preco_promocional > $itemVenda->produto->produto_preco_venda)
            ? (float) $itemVenda->produto->produto_preco_promocional
            : (float) $itemVenda->produto->produto_preco_venda;

        $desconto  = (float) $itemVenda->item_venda_desconto;
        $valorBase = round($precoBase * $novaQtd - $desconto, 2);

        $itemVenda->item_venda_quantidade            = $novaQtd;
        $itemVenda->item_venda_quantidade_tributavel = $novaQtd;
        $itemVenda->item_venda_valor_base_calculo    = $valorBase;
        $itemVenda->item_venda_valor                 = $valorBase;
        $itemVenda->item_venda_valor_icms    = round($valorBase * $itemVenda->produto->produto_valor_percentual_icms   / 100, 4);
        $itemVenda->item_venda_valor_pis     = round($valorBase * $itemVenda->produto->produto_valor_percentual_pis    / 100, 4);
        $itemVenda->item_venda_valor_cofins  = round($valorBase * $itemVenda->produto->produto_valor_percentual_cofins / 100, 4);
        $itemVenda->save();

        $this->vendaService->atualizarValoresdaVenda($venda_id);

        return response()->json([
            'success'             => 'Quantidade atualizada!',
            'item_venda_valor'    => $itemVenda->item_venda_valor,
            'item_venda_quantidade' => $itemVenda->item_venda_quantidade,
        ]);
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


        $precoBaseDesconto = ($itemVenda->produto->produto_preco_promocional > 0 && $itemVenda->produto->produto_preco_promocional > $itemVenda->produto->produto_preco_venda)
            ? (float) $itemVenda->produto->produto_preco_promocional
            : (float) $itemVenda->produto->produto_preco_venda;

        // Atualizar os valores do item com o novo desconto
        $itemVenda->item_venda_valor_base_calculo = (($precoBaseDesconto * $itemVenda->item_venda_quantidade) - $item_venda_desconto);
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
        // Recebe os IDs dos pedidos e o ID da venda do request
        $pedido_id = 1;
        $venda_id = 73;

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
                // Se o item já existe na venda, atualizar os valores
                $itemVenda->item_venda_quantidade += $item->item_pedido_quantidade;
                $itemVenda->item_venda_desconto += $item->item_pedido_desconto;
                $itemVenda->item_venda_valor += (($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais);

                $itemVenda->item_venda_quantidade_tributavel += $item->item_pedido_quantidade;
                $itemVenda->item_venda_valor_unitario_tributavel += $item->produto->produto_preco_venda - ($item->item_pedido_desconto / $item->item_pedido_quantidade);
                $itemVenda->item_venda_valor_base_calculo += (($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais);
                $itemVenda->item_venda_valor_icms += ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais) * $item->produto->produto_valor_percentual_icms) / 100;
                $itemVenda->item_venda_valor_pis += ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais) * $item->produto->produto_valor_percentual_pis) / 100;
                $itemVenda->item_venda_valor_cofins += ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais) * $item->produto->produto_valor_percentual_cofins) / 100;
                $itemVenda->save();
            } else {
                // Buscar o último número sequencial da venda
                $lastItem = ItensVenda::where('item_venda_venda_id', $venda_id)
                    ->orderBy('item_numero', 'desc')
                    ->first();

                // Definir o próximo número sequencial
                $nextItemNumber = $lastItem ? $lastItem->item_numero + 1 : 1;
                $quantidade_unitaria = 1;
                if ($item->item_pedido_quantidade < 1) {
                    $quantidade_unitaria = $item->item_pedido_quantidade;
                }

                // Se o item não existe na venda, adicionar o item
                ItensVenda::create([
                    'item_numero' => $nextItemNumber,
                    'item_venda_venda_id' => $venda_id,
                    'item_venda_produto_id' => $item->item_pedido_produto_id,
                    'item_venda_quantidade' => $item->item_pedido_quantidade,
                    'item_venda_valor_unitario' => $item->produto->produto_preco_venda,
                    'item_venda_desconto' => $item->item_pedido_desconto,
                    'item_venda_valor' => (($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais),
                    'item_venda_status' => 'INSERIDO',

                    //Impostos
                    'item_venda_quantidade_tributavel' => $item->item_pedido_quantidade,
                    'item_venda_valor_unitario_tributavel' => $item->produto->produto_preco_venda - ($item->item_pedido_desconto / $item->item_pedido_quantidade),
                    'item_venda_valor_base_calculo' => (($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais),
                    'item_venda_valor_icms' => ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais) * $item->produto->produto_valor_percentual_icms) / 100,
                    'item_venda_valor_pis' => ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais) * $item->produto->produto_valor_percentual_pis) / 100,
                    'item_venda_valor_cofins' => ((($item->produto->produto_preco_venda * $item->item_pedido_quantidade) - $item->item_pedido_desconto + $item->item_pedido_valor_adicionais) * $item->produto->produto_valor_percentual_cofins) / 100,
                ]);
            }
        }

        // Atualizar valores da venda
        //$this->vendaService->atualizarValoresdaVenda($request->input('venda_id'));


        return response()->json(['success' => 'Adicionado']);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function listarItensVenda(Request $request)
    {
        $venda_id = $request->input('venda_id');
        $itensVenda = ItensVenda::with('produto.categoria', 'produto.ap_produto_id.adicional', 'adicionaisItemVenda.adicional')
            ->where('item_venda_venda_id', $venda_id)
            ->where('item_venda_status', 'INSERIDO')->get();

        return response()->json($itensVenda, 200);
    }

    /**
     * Atualiza os tributos do item da venda.
     */
    private function atualizarTributos($itemVenda, $item, $valorTotal)
    {
        $itemVenda->item_venda_valor_base_calculo += $valorTotal;
        $itemVenda->item_venda_valor_icms += ($valorTotal * $item->produto->produto_valor_percentual_icms) / 100;
        $itemVenda->item_venda_valor_pis += ($valorTotal * $item->produto->produto_valor_percentual_pis) / 100;
        $itemVenda->item_venda_valor_cofins += ($valorTotal * $item->produto->produto_valor_percentual_cofins) / 100;
        $itemVenda->item_venda_valor_total_tributos += ($valorTotal * ($item->produto->produto_valor_percentual_icms + $item->produto->produto_valor_percentual_pis + $item->produto->produto_valor_percentual_cofins)) / 100;
    }

    /**
     * Adiciona ou atualiza os adicionais do item.
     */
    private function adicionarOuAtualizarAdicionais($item, $itemVenda)
    {
        $adicionaisPedido = AdicionaisItemPedido::where('aip_item_pedido_id', $item->id)->get();

        foreach ($adicionaisPedido as $adicionalPedido) {
            $adicionalVenda = AdicionaisItemVenda::where('aiv_item_venda_id', $itemVenda->id)
                ->where('aiv_adicional_id', $adicionalPedido->aip_adicional_id)
                ->first();

            if ($adicionalVenda) {
                $adicionalVenda->aiv_quantidade += $adicionalPedido->aip_quantidade;
                $adicionalVenda->aiv_valor_total += $adicionalPedido->aip_valor_total;
                $adicionalVenda->save();
            } else {
                AdicionaisItemVenda::create([
                    'aiv_adicional_id' => $adicionalPedido->aip_adicional_id,
                    'aiv_item_venda_id' => $itemVenda->id,
                    'aiv_valor_unitario' => $adicionalPedido->aip_valor_unitario,
                    'aiv_quantidade' => $adicionalPedido->aip_quantidade,
                    'aiv_valor_total' => $adicionalPedido->aip_valor_total,
                ]);
            }
        }
    }

    /**
     * Adiciona Itens dos Pedidos na venda
     */
    private function adicionarItensPedidoNaVenda($venda_id, $itensPedido)
    {
        foreach ($itensPedido as $item) {

            // Verifica se o ITEM ATUAL tem adicionais
            $itemAtualTemAdicionais = (isset($item->adicionaisItemVenda) &&
                $item->adicionaisItemVenda !== null &&
                !$item->adicionaisItemVenda->isEmpty()) ||
                ($item->item_pedido_valor_adicionais > 0);

            $itemVenda = ItensVenda::where('item_venda_produto_id', $item->item_pedido_produto_id)
                ->where('item_venda_venda_id', $venda_id)
                ->where('item_venda_valor_adicionais', 0)
                ->first();

            // Só atualiza item existente se o item ATUAL NÃO tiver adicionais
            if ($itemVenda && !$itemAtualTemAdicionais) {
                // Atualizar item existente
                $itemVenda->item_venda_quantidade += $item->item_pedido_quantidade;
                $itemVenda->item_venda_desconto += $item->item_pedido_desconto;
                $itemVenda->item_venda_valor += $item->item_pedido_valor;
                $itemVenda->item_venda_quantidade_tributavel += $item->item_pedido_quantidade;
                $this->atualizarTributos($itemVenda, $item, $item->item_pedido_valor);
                $itemVenda->save();
            } else {
                // Criar novo item (sempre cria se o item ATUAL tiver adicionais)
                $lastItem = ItensVenda::where('item_venda_venda_id', $venda_id)
                    ->orderBy('item_numero', 'desc')
                    ->first();
                $nextItemNumber = $lastItem ? $lastItem->item_numero + 1 : 1;

                $precoBase = ($item->produto->produto_preco_promocional > 0 && $item->produto->produto_preco_promocional > $item->produto->produto_preco_venda)
                    ? (float) $item->produto->produto_preco_promocional
                    : (float) $item->produto->produto_preco_venda;

                if ($item->item_pedido_quantidade == 0.5) {
                    $valorUnitario = $precoBase + $item->item_pedido_valor_adicionais;
                    $valor = ($valorUnitario * $item->item_pedido_quantidade) - $item->item_pedido_desconto;
                    $itemVenda = ItensVenda::create([
                        'item_numero' => $nextItemNumber,
                        'item_venda_venda_id' => $venda_id,
                        'item_venda_produto_id' => $item->item_pedido_produto_id,
                        'item_venda_quantidade' => $item->item_pedido_quantidade,
                        'item_venda_valor_unitario' => $valorUnitario,
                        'item_venda_valor_adicionais' => $item->item_pedido_valor_adicionais / 2, //Divide por dois pq na tela de pedido quando a quantidade é 0.5 o valor adicional é x2 para dar o valor_total do item correto
                        'item_venda_desconto' => $item->item_pedido_desconto,
                        'item_venda_valor' => $valor,
                        'item_venda_status' => 'INSERIDO',
                        'item_venda_quantidade_tributavel' => $item->item_pedido_quantidade,
                        'item_venda_valor_unitario_tributavel' => $valorUnitario,
                        'item_venda_valor_base_calculo' => $item->item_pedido_valor,
                        'item_venda_valor_icms' => ($item->item_pedido_valor * $item->produto->produto_valor_percentual_icms) / 100,
                        'item_venda_valor_pis' => ($item->item_pedido_valor * $item->produto->produto_valor_percentual_pis) / 100,
                        'item_venda_valor_cofins' => ($item->item_pedido_valor * $item->produto->produto_valor_percentual_cofins) / 100,
                        'item_venda_valor_total_tributos' => ($item->item_pedido_valor * ($item->produto->produto_valor_percentual_icms + $item->produto->produto_valor_percentual_pis + $item->produto->produto_valor_percentual_cofins)) / 100,
                    ]);
                } else {
                    $valorEfetivo = $item->item_pedido_valor - $item->item_pedido_desconto;
                    $itemVenda = ItensVenda::create([
                        'item_numero' => $nextItemNumber,
                        'item_venda_venda_id' => $venda_id,
                        'item_venda_produto_id' => $item->item_pedido_produto_id,
                        'item_venda_quantidade' => $item->item_pedido_quantidade,
                        'item_venda_valor_unitario' => $item->item_pedido_valor_unitario,
                        'item_venda_valor_adicionais' => $item->item_pedido_valor_adicionais,
                        'item_venda_desconto' => $item->item_pedido_desconto,
                        'item_venda_valor' => $valorEfetivo,
                        'item_venda_status' => 'INSERIDO',
                        'item_venda_quantidade_tributavel' => $item->item_pedido_quantidade,
                        'item_venda_valor_unitario_tributavel' => $item->item_pedido_valor_unitario,
                        'item_venda_valor_base_calculo' => $valorEfetivo,
                        'item_venda_valor_icms' => ($valorEfetivo * $item->produto->produto_valor_percentual_icms) / 100,
                        'item_venda_valor_pis' => ($valorEfetivo * $item->produto->produto_valor_percentual_pis) / 100,
                        'item_venda_valor_cofins' => ($valorEfetivo * $item->produto->produto_valor_percentual_cofins) / 100,
                        'item_venda_valor_total_tributos' => ($valorEfetivo * ($item->produto->produto_valor_percentual_icms + $item->produto->produto_valor_percentual_pis + $item->produto->produto_valor_percentual_cofins)) / 100,
                    ]);
                }
            }

            // Atualizar ou criar adicionais
            $this->adicionarOuAtualizarAdicionais($item, $itemVenda);
        }
    }


    /**
     * Remove os adicionais do item.
     */
    private function removeAdicionais($itemVenda)
    {
        $adicionaisVenda = AdicionaisItemVenda::where('aiv_item_venda_id', $itemVenda->id)->get();

        foreach ($adicionaisVenda as $adicionalVenda) {

            $adicionalVenda->delete();
        }
    }
}
