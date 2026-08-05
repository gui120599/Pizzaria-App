<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use App\Models\Venda;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Carbon\Carbon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class FinanceiroStatsOverview extends BaseWidget
{
    use HasWidgetShield;
    use InteractsComPeriodo;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Indicadores do período';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        [$inicio, $fim] = $this->periodo();
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

        $cmvPct = $faturamento > 0 ? $cmv / $faturamento * 100 : 0.0;
        $margem = $faturamento - $cmv;
        $margemPct = $faturamento > 0 ? $margem / $faturamento * 100 : 0.0;

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
        $taxaCancel = $totalTentativas > 0 ? $canceladas / $totalTentativas * 100 : 0.0;

        $descontoPct = $faturamento > 0 ? $descontos / ($faturamento + $descontos) * 100 : 0.0;

        // CMV ideal em restaurante costuma ficar entre 28% e 35%.
        $cmvCor = match (true) {
            $cmvPct <= 35 => 'success',
            $cmvPct <= 40 => 'warning',
            default => 'danger',
        };

        return [
            Stat::make('Faturamento', $this->brl($faturamento))
                ->description("{$numVendas} vendas finalizadas")
                ->descriptionIcon('heroicon-m-banknotes')
                ->chart($this->sparklineFaturamento($inicio, $fim, $filtrandoProduto))
                ->color('success'),

            Stat::make('Qtd. total de produtos', number_format($qtdProdutos, 0, ',', '.'))
                ->description('Unidades vendidas no período')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('info'),

            Stat::make('Ticket médio', $this->brl($ticket))
                ->description('Faturamento ÷ nº de vendas')
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('info'),

            Stat::make('CMV', $this->brl($cmv))
                ->description("{$this->pct($cmvPct)} do faturamento")
                ->descriptionIcon('heroicon-m-cube')
                ->color($cmvCor),

            Stat::make('Margem bruta', $this->brl($margem))
                ->description("{$this->pct($margemPct)} de margem")
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color($margemPct >= 60 ? 'success' : ($margemPct >= 50 ? 'warning' : 'danger')),

            Stat::make('Descontos concedidos', $this->brl($descontos))
                ->description("{$this->pct($descontoPct)} sobre o bruto")
                ->descriptionIcon('heroicon-m-tag')
                ->color($descontos > 0 ? 'warning' : 'gray'),

            Stat::make('Cancelamentos', (string) $canceladas)
                ->description("{$this->pct($taxaCancel)} das vendas")
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($taxaCancel >= 5 ? 'danger' : 'gray'),
        ];
    }

    /**
     * Série de faturamento diário para o sparkline (até os últimos pontos do período).
     * Segue os mesmos filtros de tipo de entrega/categoria/produto do restante do card.
     *
     * @return array<int, float>
     */
    private function sparklineFaturamento(Carbon $inicio, Carbon $fim, bool $filtrandoProduto): array
    {
        if ($filtrandoProduto) {
            return $this->itensFiltradosQuery($inicio, $fim)
                ->selectRaw('DATE(vendas.venda_datahora_finalizada) as dia, SUM(itens_vendas.item_venda_valor) as total')
                ->groupBy('dia')
                ->orderBy('dia')
                ->pluck('total')
                ->map(fn ($v) => (float) $v)
                ->all();
        }

        return $this->vendasFiltradasQuery($inicio, $fim)
            ->selectRaw('DATE(venda_datahora_finalizada) as dia, SUM(venda_valor_total) as total')
            ->groupBy('dia')
            ->orderBy('dia')
            ->pluck('total')
            ->map(fn ($v) => (float) $v)
            ->all();
    }
}
