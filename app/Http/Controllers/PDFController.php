<?php

namespace App\Http\Controllers;

use App\Models\ItensPedido;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\Venda;
use Illuminate\Http\Request;
use PDF;

class PDFController extends Controller
{
    public function pedidoPDF(Request $request)
    {
        $pedido_id = $request->id;
        $itensInseridoPedido = ItensPedido::with('produto') // Carregue o relacionamento 'produto'
            ->where('item_pedido_pedido_id', $pedido_id)
            ->where('item_pedido_status', 'INSERIDO')
            ->get();
        $pedido = Pedido::find($pedido_id);
        return view('pedidoPDF', ['itens_inserido_pedido' => $itensInseridoPedido, 'pedido' => $pedido]);
    }

    public function sessaoMesaPDF(Request $request)
    {
        $sessaoMesaId = $request->id;
        $sessaoMesa = SessaoMesa::find($sessaoMesaId);

        // Carregar itens de pedido com os pedidos relacionados
        $itensInseridoPedido = ItensPedido::whereHas('pedido', function ($query) use ($sessaoMesaId) {
            $query->where('pedido_sessao_mesa_id', $sessaoMesaId)->where('pedido_status', '<>', 'CANCELADO');
        })->where('item_pedido_status', 'INSERIDO')->with('pedido')->get();

        $pedidos = Pedido::where('pedido_sessao_mesa_id', $sessaoMesaId)->where('pedido_status', '<>', 'CANCELADO')->get();

        return view('sessaoMesaPDF', [
            'sessao_mesa' => $sessaoMesa,
            'itens_inserido_pedido' => $itensInseridoPedido,
            'pedidos' => $pedidos
        ]);
    }

    public function sessaoCaixaPDF(Request $request)
    {
        $sessaoCaixaId = $request->id;
        $sessaoCaixa = SessaoCaixa::with('caixa')->find($sessaoCaixaId);

        // Verificação se o objeto $sessaoCaixa foi encontrado
        if (!$sessaoCaixa) {
            return back()->withErrors('Sessão de caixa não encontrada.');
        }

        // Verificação se há um objeto Caixa relacionado
        if (!$sessaoCaixa->caixa) {
            return back()->withErrors('Caixa não encontrado para esta sessão.');
        }

        $movSaidas = MovimentacoesSessaoCaixa::where('mov_sessaocaixa_id',$sessaoCaixaId)->where('mov_tipo','SAIDA')->get();

        $vendas = Venda::where('venda_sessao_caixa_id', $sessaoCaixaId)
            ->where('venda_status', 'FINALIZADA')
            ->with('pagamentos')
            ->get();

        $pagamentos = PagamentosVenda::whereHas('venda', function ($query) use ($sessaoCaixaId) {
            $query->where('venda_sessao_caixa_id', $sessaoCaixaId);
        })->with('opcaoPagamento')->get();

        $opcoesPagamentos = OpcoesPagamento::whereHas('pagamentosVenda', function ($query) use ($sessaoCaixaId) {
            $query->whereHas('venda', function ($subQuery) use ($sessaoCaixaId) {
                $subQuery->where('venda_sessao_caixa_id', $sessaoCaixaId);
            });
        })->get();
        

        return view('sessaoCaixaPDF', [
            'sessao_caixa' => $sessaoCaixa,
            'vendas' => $vendas,
            'pagamentos' => $pagamentos,
            'opcoes_pagamentos' => $opcoesPagamentos,
            'saidas' => $movSaidas
        ]);
        //dd($movSaidas);
    }
}
