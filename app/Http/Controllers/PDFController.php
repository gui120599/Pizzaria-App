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
use Carbon\Carbon;
use Illuminate\Http\Request;
use PDF;

class PDFController extends Controller
{
    public function pedidoPDF(Request $request)
    {
        $pedido_id = $request->id;
        $itensInseridoPedido = ItensPedido::with(['produto.categoria', 'adicionaisItemPedido.adicional'])
            ->where('item_pedido_pedido_id', $pedido_id)
            ->where('item_pedido_status', 'INSERIDO')
            ->get();
        $pedido = Pedido::with(['cliente', 'garcom', 'opcaoEntrega', 'sessaoMesa.mesa'])->find($pedido_id);
        return view('pedidoPDF', ['itens_inserido_pedido' => $itensInseridoPedido, 'pedido' => $pedido]);
    }

    public function sessaoMesaPDF(Request $request)
    {
        $sessaoMesaId = $request->id;
        $sessaoMesa = SessaoMesa::with(['mesa', 'cliente', 'garcom'])->find($sessaoMesaId);

        $itensInseridoPedido = ItensPedido::whereHas('pedido', function ($query) use ($sessaoMesaId) {
            $query->where('pedido_sessao_mesa_id', $sessaoMesaId)->where('pedido_status', '<>', 'CANCELADO');
        })
        ->where('item_pedido_status', 'INSERIDO')
        ->with(['pedido.garcom', 'produto.categoria', 'adicionaisItemPedido.adicional', 'cliente'])
        ->get();

        $pedidos = Pedido::where('pedido_sessao_mesa_id', $sessaoMesaId)
            ->where('pedido_status', '<>', 'CANCELADO')
            ->get();

        // Agrupa por cliente: usa item_pedido_cliente_id; null vai para chave 0
        $itensPorCliente = $itensInseridoPedido->groupBy(fn($item) => $item->item_pedido_cliente_id ?? 0);

        return view('sessaoMesaPDF', [
            'sessao_mesa'       => $sessaoMesa,
            'itens_por_cliente' => $itensPorCliente,
            'pedidos'           => $pedidos,
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

        $movSaidas = MovimentacoesSessaoCaixa::where('mov_sessaocaixa_id', $sessaoCaixaId)->where('mov_tipo', 'SAIDA')->get();

        $vendas = Venda::where('venda_sessao_caixa_id', $sessaoCaixaId)
            ->where('venda_status', 'FINALIZADA')
            ->with('pagamentos')
            ->get();

        $pagamentos = PagamentosVenda::whereHas('venda', function ($query) use ($sessaoCaixaId) {
            $query->where('venda_sessao_caixa_id', $sessaoCaixaId)->where('venda_status','FINALIZADA');
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

    public function pedidosEntreguesFinalizadosCanceladosPDF($datahora_abertura)
    {
        // Obter a data de início a partir do parâmetro da URL
        $dataInicio = Carbon::parse($datahora_abertura);

        // Criar uma cópia de $dataInicio e adicionar um dia
        $dataFinal = $dataInicio->copy()->addDay()->format('Y-m-d');

        // Definir o horário de 17h do dia inicial
        $DatahoraInicio = $dataInicio->copy()->setTime(07, 0, 0); // 17:00:00 no dia inicial

        // Definir o horário de 03h do dia final
        $DatahoraFinal = Carbon::parse($dataFinal)->setTime(3, 0, 0); // 03:00:00 no dia final

        // Buscar pedidos com status ENTREGUE, FINALIZADO ou CANCELADO no intervalo de tempo
        $pedidos = Pedido::whereBetween('pedido_datahora_abertura', [$DatahoraInicio, $DatahoraFinal])->get();

        return view('pedidosEntreguesFinalizadosCanceladosPDF', [
            'pedidos' => $pedidos
        ]);
    }

    public function pedidosEntregasPDF($datahora_abertura)
    {
        // Obter a data de início a partir do parâmetro da URL
        $dataInicio = Carbon::parse($datahora_abertura);

        // Criar uma cópia de $dataInicio e adicionar um dia
        $dataFinal = $dataInicio->copy()->addDay()->format('Y-m-d');

        // Definir o horário de 17h do dia inicial
        $DatahoraInicio = $dataInicio->copy()->setTime(07, 0, 0); // 17:00:00 no dia inicial

        // Definir o horário de 03h do dia final
        $DatahoraFinal = Carbon::parse($dataFinal)->setTime(3, 0, 0); // 03:00:00 no dia final

        // Buscar pedidos com status ENTREGUE, FINALIZADO ou CANCELADO no intervalo de tempo
        $pedidos = Pedido::whereBetween('pedido_datahora_abertura', [$DatahoraInicio, $DatahoraFinal])->get();

        return view('pedidosEntregasPDF', [
            'pedidos' => $pedidos
        ]);
    }

}
