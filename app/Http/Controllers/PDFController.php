<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Lancamento;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\Venda;
use App\Support\ContaMesa;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

    /**
     * Comprovante de uma Venda finalizada (recibo simples, no mesmo estilo de
     * pedidoPDF) — diferente de vendaPendentePDF, que é o comprovante de
     * débito em aberto (fiado) indexado por Lancamento, não por Venda.
     */
    public function vendaPDF(Request $request)
    {
        $venda = Venda::with([
            'cliente',
            'sessaoCaixa.user',
            'itensVenda' => fn ($query) => $query->where('item_venda_status', 'INSERIDO'),
            'itensVenda.produto.categoria',
            'itensVenda.adicionaisItemVenda.adicional',
            'pagamentos.opcaoPagamento',
        ])->findOrFail($request->id);

        return view('vendaPDF', ['venda' => $venda]);
    }

    /**
     * Pré-conta da mesa. Mesmo escopo da baixa na venda (ContaMesa::itensEmAberto):
     * ignora rascunhos, cancelados, finalizados e itens já lançados numa venda.
     *
     * ?modo=cliente (padrão, por pessoa) | agrupado (itens iguais somados) |
     * rodada (cada envio com data/hora e garçom); ?cliente={id} imprime a
     * comanda individual de uma pessoa (0 = itens da mesa sem pessoa).
     */
    public function sessaoMesaPDF(Request $request)
    {
        $sessaoMesa = SessaoMesa::with(['mesa', 'cliente', 'garcom'])->findOrFail($request->id);
        $modo = in_array($request->query('modo'), ['agrupado', 'rodada'], true) ? $request->query('modo') : 'cliente';
        $clienteId = $request->query('cliente') !== null ? (int) $request->query('cliente') : null;

        $itens = ContaMesa::itensEmAberto($sessaoMesa->id, $clienteId);

        $blocos = match ($modo) {
            'agrupado' => [[
                'titulo' => null,
                'subtitulo' => null,
                'subtotal' => round((float) $itens->sum('item_pedido_valor'), 2),
                'agrupados' => $this->agruparItensIguais($itens),
            ]],
            'rodada' => $itens->groupBy('item_pedido_pedido_id')->map(fn ($daRodada) => [
                'titulo' => 'Rodada #'.$daRodada->first()->item_pedido_pedido_id,
                'subtitulo' => collect([
                    $daRodada->first()->pedido?->pedido_datahora_abertura
                        ? Carbon::parse($daRodada->first()->pedido->pedido_datahora_abertura)->format('d/m H:i')
                        : null,
                    $daRodada->first()->pedido?->garcom?->name_first,
                ])->filter()->implode(' · '),
                'subtotal' => round((float) $daRodada->sum('item_pedido_valor'), 2),
                'itens' => $daRodada,
            ])->values()->all(),
            default => $itens->groupBy(fn ($item) => $item->item_pedido_cliente_id ?? 0)->map(fn ($daPessoa, $id) => [
                'titulo' => $id === 0 ? 'Mesa geral' : ($daPessoa->first()->cliente?->cliente_nome ?? 'Cliente #'.$id),
                'subtitulo' => null,
                'subtotal' => round((float) $daPessoa->sum('item_pedido_valor'), 2),
                'itens' => $daPessoa,
            ])->values()->all(),
        };

        $tituloImpressao = match (true) {
            $clienteId === 0 => 'Comanda — Mesa geral',
            $clienteId !== null => 'Comanda de '.(Cliente::find($clienteId)?->cliente_nome ?? 'Cliente'),
            $modo === 'agrupado' => 'Pré-conta (itens agrupados)',
            $modo === 'rodada' => 'Pré-conta por rodada',
            default => 'Comanda de Mesa',
        };

        return view('sessaoMesaPDF', [
            'sessao_mesa' => $sessaoMesa,
            'blocos' => $blocos,
            'titulo_impressao' => $tituloImpressao,
            // Desconto pelo conjunto de itens exibido (não pelo cabeçalho do pedido).
            'total_desconto' => round($itens->sum('item_pedido_desconto'), 2),
        ]);
    }

    /**
     * Soma itens iguais — mesmo nome (com sabores), adicionais e observação.
     *
     * @param  Collection<int, ItensPedido>  $itens
     * @return array<int, array{nome: string, adicionais: string, observacao: string, quantidade: float, valor: float, desconto: float}>
     */
    private function agruparItensIguais(Collection $itens): array
    {
        return $itens
            ->map(fn (ItensPedido $item) => [
                'nome' => $item->nomeProduto(),
                'adicionais' => $item->adicionaisItemPedido->map(fn ($a) => $a->adicional?->adicional_nome)->filter()->sort()->implode(', '),
                'observacao' => trim((string) $item->item_pedido_observacao),
                'quantidade' => (float) $item->item_pedido_quantidade,
                'valor' => (float) $item->item_pedido_valor,
                'desconto' => (float) $item->item_pedido_desconto,
            ])
            ->groupBy(fn (array $linha) => $linha['nome'].'|'.$linha['adicionais'].'|'.$linha['observacao'])
            ->map(fn (Collection $iguais) => [
                ...$iguais->first(),
                'quantidade' => round($iguais->sum('quantidade'), 4),
                'valor' => round($iguais->sum('valor'), 2),
                'desconto' => round($iguais->sum('desconto'), 2),
            ])
            ->values()
            ->all();
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
            // Pizza de sabores tem linha própria na venda (nunca mescla) —
            // não entra no abatimento por produto.
            foreach ($pedido->item_pedido_pedido_id->reject->ehMultiSabor() as $item) {
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
        foreach ($venda->itensVenda->reject->ehMultiSabor()->groupBy('item_venda_produto_id') as $produtoId => $itens) {
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
