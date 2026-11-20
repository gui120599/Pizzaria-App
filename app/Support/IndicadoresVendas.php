<?php

namespace App\Support;

use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use App\Models\ItensVenda;
use App\Models\Venda;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores de vendas com os mesmos filtros e a mesma conta do Painel
 * Financeiro (FinanceiroStatsOverview / TopProdutosVendidos). Usado pelo
 * widget e pelas tools do conector "PizzariaAPP Gestão" do Claude, para o
 * número da conversa bater com o da tela.
 *
 * Filtros (opcionais, como os da página): tipos_entrega, categorias, produtos.
 */
class IndicadoresVendas
{
    use InteractsComPeriodo;

    /** @param  array<string, mixed>  $pageFilters */
    public function __construct(public array $pageFilters = []) {}

    /**
     * Faturamento, nº de vendas, ticket, CMV, margem, descontos e cancelamentos
     * do período — os números do Painel Financeiro.
     *
     * @return array<string, float|int>
     */
    public function indicadores(Carbon $inicio, Carbon $fim): array
    {
        $tipos = $this->tiposEntregaSelecionados();
        $filtrandoProduto = $this->filtrandoPorProduto();

        $vendas = $this->vendasFiltradasQuery($inicio, $fim);
        $numVendas = (clone $vendas)->count();
        $descontos = (float) (clone $vendas)->sum('venda_valor_desconto');

        // Quando há filtro de categoria/produto, faturamento e CMV vêm da
        // receita/custo dos itens que batem no filtro — não do total da venda,
        // que pode incluir outros produtos fora do recorte.
        $itens = $this->itensFiltradosQuery($inicio, $fim);

        $faturamento = $filtrandoProduto
            ? (float) (clone $itens)->sum('itens_vendas.item_venda_valor')
            : (float) (clone $vendas)->sum('venda_valor_total');

        $ticket = $numVendas > 0 ? $faturamento / $numVendas : 0.0;

        // CMV: usa o custo congelado no momento da venda (item_venda_custo_unitario,
        // preenchido pelo ItensVendaObserver). Fiel ao período mesmo que o custo mude.
        $cmv = (float) (clone $itens)->sum(DB::raw('itens_vendas.item_venda_quantidade * itens_vendas.item_venda_custo_unitario'));

        $margem = $faturamento - $cmv;

        // Soma de unidades vendidas no período (itens filtrados por categoria/produto,
        // se houver), independente do filtro de categoria/produto para faturamento/CMV.
        $qtdProdutos = (float) (clone $itens)->sum('itens_vendas.item_venda_quantidade');

        // Cancelamentos no período (usa a data de início, pois canceladas não têm finalização).
        // Não é filtrado por categoria/produto: cancelamento é um evento da venda inteira.
        $canceladasQuery = Venda::query()
            ->where('venda_status', 'CANCELADA')
            ->whereBetween('venda_datahora_iniciada', [$inicio, $fim]);

        if ($tipos !== []) {
            $canceladasQuery->whereHas('pedidos', fn ($q) => $q->whereIn('pedido_opcaoentrega_id', $tipos));
        }

        $canceladas = $canceladasQuery->count();
        $totalTentativas = $numVendas + $canceladas;

        return [
            'vendas' => $numVendas,
            'faturamento' => $faturamento,
            'ticket' => $ticket,
            'cmv' => $cmv,
            'cmv_pct' => $faturamento > 0 ? $cmv / $faturamento * 100 : 0.0,
            'margem' => $margem,
            'margem_pct' => $faturamento > 0 ? $margem / $faturamento * 100 : 0.0,
            'descontos' => $descontos,
            'desconto_pct' => $faturamento > 0 ? $descontos / ($faturamento + $descontos) * 100 : 0.0,
            'qtd_produtos' => $qtdProdutos,
            'canceladas' => $canceladas,
            'taxa_cancelamento' => $totalTentativas > 0 ? $canceladas / $totalTentativas * 100 : 0.0,
        ];
    }

    /**
     * Período anterior de mesma duração (mesma regra do comparativo do painel).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function anteriorA(Carbon $inicio, Carbon $fim): array
    {
        $filtros = $this->pageFilters;
        $this->pageFilters = [...$filtros, 'inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString()];
        $anterior = $this->periodoAnterior();
        $this->pageFilters = $filtros;

        return $anterior;
    }

    /**
     * Produtos mais vendidos (cada sabor de pizza conta pela sua fração), como
     * a tabela "Produtos mais vendidos no período".
     *
     * @return Collection<int, array{produto_id: int, produto: string, categoria: string, quantidade: float, receita: float, custo: float, margem: float}>
     */
    public function rankingProdutos(Carbon $inicio, Carbon $fim, int $limite = 10, string $ordem = 'receita'): Collection
    {
        return ItensVenda::query()->fromSub(
            $this->itensFiltradosQuery($inicio, $fim, porSabor: true)
                ->leftJoin('categorias', 'categorias.id', '=', 'produtos.produto_categoria_id')
                ->groupBy('itens_vendas.item_venda_produto_id', 'produtos.produto_descricao', 'categorias.categoria_nome')
                ->selectRaw('
                    itens_vendas.item_venda_produto_id as id,
                    produtos.produto_descricao as produto,
                    COALESCE(categorias.categoria_nome, "Sem categoria") as categoria,
                    SUM(itens_vendas.item_venda_quantidade) as qtd,
                    SUM(itens_vendas.item_venda_valor) as receita,
                    SUM(itens_vendas.item_venda_quantidade * itens_vendas.item_venda_custo_unitario) as custo
                '),
            'itens_vendas',
        )
            ->orderByDesc($ordem === 'quantidade' ? 'qtd' : 'receita')
            ->limit($limite)
            ->get()
            ->map(fn (ItensVenda $linha): array => [
                'produto_id' => (int) $linha->id,
                'produto' => (string) $linha->produto,
                'categoria' => (string) $linha->categoria,
                'quantidade' => round((float) $linha->qtd, 3),
                'receita' => round((float) $linha->receita, 2),
                'custo' => round((float) $linha->custo, 2),
                'margem' => round((float) $linha->receita - (float) $linha->custo, 2),
            ]);
    }
}
