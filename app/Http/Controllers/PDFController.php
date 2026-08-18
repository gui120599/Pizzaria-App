<?php

namespace App\Http\Controllers;

use App\Models\ItensPedido;
use App\Models\Lancamento;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\Venda;
use Carbon\Carbon;
use Illuminate\Http\Request;

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

        // Mesmo escopo da baixa na venda: ignora rascunhos (INICIADO), cancelados,
        // já finalizados e itens já vinculados a uma venda (cobrados/lançados).
        // Assim o total da comanda bate com o valor a cobrar na venda.
        $itensInseridoPedido = ItensPedido::whereHas('pedido', function ($query) use ($sessaoMesaId) {
            $query->where('pedido_sessao_mesa_id', $sessaoMesaId)
                ->whereNotIn('pedido_status', ['INICIADO', 'CANCELADO', 'FINALIZADO']);
        })
            ->where('item_pedido_status', 'INSERIDO')
            ->whereNull('item_pedido_venda_id')
            ->with(['pedido.garcom', 'produto.categoria', 'adicionaisItemPedido.adicional', 'cliente'])
            ->get();

        // Desconto pelo conjunto de itens exibido (não pelo cabeçalho do pedido),
        // para ficar correto também em pagamentos parciais.
        $totalDesconto = round($itensInseridoPedido->sum('item_pedido_desconto'), 2);

        // Agrupa por cliente: usa item_pedido_cliente_id; null vai para chave 0
        $itensPorCliente = $itensInseridoPedido->groupBy(fn ($item) => $item->item_pedido_cliente_id ?? 0);

        return view('sessaoMesaPDF', [
            'sessao_mesa' => $sessaoMesa,
            'itens_por_cliente' => $itensPorCliente,
            'total_desconto' => $totalDesconto,
        ]);
    }

    /**
     * Comprovante de um título fiado (Lancamento tipo=Receber) em aberto, mostrando
     * ao cliente o que ele está devendo: pedidos avulsos, sessões de mesa e produtos
     * lançados direto (sem pedido/mesa) que compõem a venda que originou o título.
     *
     * Como App\Models\ItensVenda não guarda a origem depois que os itens são
     * mesclados por produto (ver OperarVenda::adicionarItensPedidoNaVenda), os
     * "produtos avulsos" são reconciliados por subtração: quantidade total do
     * produto na venda menos a quantidade já contabilizada nos pedidos/mesas —
     * correto porque a quantidade mesclada é sempre a soma de todas as origens.
     */
    public function vendaPendentePDF(Request $request)
    {
        $lancamento = Lancamento::with(['venda.cliente', 'venda.itensVenda.produto.categoria'])->findOrFail($request->id);
        $venda = $lancamento->venda;

        $pedidosAvulsos = Pedido::whereHas('item_pedido_pedido_id', function ($query) use ($venda) {
            $query->where('item_pedido_venda_id', $venda->id);
        })
            ->whereNull('pedido_sessao_mesa_id')
            ->with([
                'cliente',
                'item_pedido_pedido_id' => fn ($query) => $query->where('item_pedido_venda_id', $venda->id),
                'item_pedido_pedido_id.produto.categoria',
                'item_pedido_pedido_id.adicionaisItemPedido.adicional',
            ])
            ->get();

        $sessoesMesa = SessaoMesa::whereHas('pedidos.item_pedido_pedido_id', function ($query) use ($venda) {
            $query->where('item_pedido_venda_id', $venda->id);
        })
            ->with([
                'mesa',
                'pedidos' => function ($query) use ($venda) {
                    $query->whereHas('item_pedido_pedido_id', fn ($q) => $q->where('item_pedido_venda_id', $venda->id))
                        ->with([
                            'item_pedido_pedido_id' => fn ($q) => $q->where('item_pedido_venda_id', $venda->id),
                            'item_pedido_pedido_id.produto.categoria',
                            'item_pedido_pedido_id.adicionaisItemPedido.adicional',
                        ]);
                },
            ])
            ->get();

        $quantidadePorProdutoEmPedidos = [];
        $somarPedido = function (Pedido $pedido) use (&$quantidadePorProdutoEmPedidos): void {
            foreach ($pedido->item_pedido_pedido_id as $item) {
                $quantidadePorProdutoEmPedidos[$item->item_pedido_produto_id] =
                    ($quantidadePorProdutoEmPedidos[$item->item_pedido_produto_id] ?? 0.0) + (float) $item->item_pedido_quantidade;
            }
        };
        foreach ($pedidosAvulsos as $pedido) {
            $somarPedido($pedido);
        }
        foreach ($sessoesMesa as $sessaoMesa) {
            foreach ($sessaoMesa->pedidos as $pedido) {
                $somarPedido($pedido);
            }
        }

        $produtosAvulsos = collect();
        foreach ($venda->itensVenda->groupBy('item_venda_produto_id') as $produtoId => $itens) {
            $quantidadeTotal = (float) $itens->sum('item_venda_quantidade');
            $quantidadeEmPedidos = $quantidadePorProdutoEmPedidos[$produtoId] ?? 0.0;
            $quantidadeAvulsa = round($quantidadeTotal - $quantidadeEmPedidos, 3);

            if ($quantidadeAvulsa <= 0) {
                continue;
            }

            $produtosAvulsos->push([
                'produto' => $itens->first()->produto,
                'quantidade' => $quantidadeAvulsa,
                'valor' => $quantidadeTotal > 0
                    ? round((float) $itens->sum('item_venda_valor') * ($quantidadeAvulsa / $quantidadeTotal), 2)
                    : 0.0,
            ]);
        }

        return view('vendaPendentePDF', [
            'lancamento' => $lancamento,
            'venda' => $venda,
            'pedidos_avulsos' => $pedidosAvulsos,
            'sessoes_mesa' => $sessoesMesa,
            'produtos_avulsos' => $produtosAvulsos,
        ]);
    }

    public function sessaoCaixaPDF(Request $request)
    {
        $sessaoCaixaId = $request->id;
        $sessaoCaixa = SessaoCaixa::with('caixa')->find($sessaoCaixaId);

        // Verificação se o objeto $sessaoCaixa foi encontrado
        if (! $sessaoCaixa) {
            return back()->withErrors('Sessão de caixa não encontrada.');
        }

        // Verificação se há um objeto Caixa relacionado
        if (! $sessaoCaixa->caixa) {
            return back()->withErrors('Caixa não encontrado para esta sessão.');
        }

        $movSaidas = MovimentacoesSessaoCaixa::where('mov_sessaocaixa_id', $sessaoCaixaId)->where('mov_tipo', 'SAIDA')->get();

        $vendas = Venda::where('venda_sessao_caixa_id', $sessaoCaixaId)
            ->where('venda_status', 'FINALIZADA')
            ->with('pagamentos')
            ->get();

        $pagamentos = PagamentosVenda::whereHas('venda', function ($query) use ($sessaoCaixaId) {
            $query->where('venda_sessao_caixa_id', $sessaoCaixaId)->where('venda_status', 'FINALIZADA');
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
            'saidas' => $movSaidas,
        ]);
        // dd($movSaidas);
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
            'pedidos' => $pedidos,
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
            'pedidos' => $pedidos,
        ]);
    }
}
