<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use App\Models\ItensVenda;
use App\Models\Venda;
use Carbon\Carbon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class FinanceiroStatsOverview extends BaseWidget
{
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

        $vendas = Venda::query()
            ->where('venda_status', 'FINALIZADA')
            ->whereBetween('venda_datahora_finalizada', [$inicio, $fim]);

        $faturamento = (float) (clone $vendas)->sum('venda_valor_total');
        $numVendas = (clone $vendas)->count();
        $descontos = (float) (clone $vendas)->sum('venda_valor_desconto');
        $ticket = $numVendas > 0 ? $faturamento / $numVendas : 0.0;

        // CMV: usa o custo médio ATUAL do produto (itens_vendas não guarda o
        // custo no momento da venda). É uma aproximação aceitável para gestão.
        $cmv = (float) ItensVenda::query()
            ->join('vendas', 'vendas.id', '=', 'itens_vendas.item_venda_venda_id')
            ->join('produtos', 'produtos.id', '=', 'itens_vendas.item_venda_produto_id')
            ->where('vendas.venda_status', 'FINALIZADA')
            ->where('itens_vendas.item_venda_status', 'INSERIDO')
            ->whereBetween('vendas.venda_datahora_finalizada', [$inicio, $fim])
            ->sum(DB::raw('itens_vendas.item_venda_quantidade * produtos.produto_custo_medio'));

        $cmvPct = $faturamento > 0 ? $cmv / $faturamento * 100 : 0.0;
        $margem = $faturamento - $cmv;
        $margemPct = $faturamento > 0 ? $margem / $faturamento * 100 : 0.0;

        // Cancelamentos no período (usa a data de início, pois canceladas não têm finalização).
        $canceladas = Venda::query()
            ->where('venda_status', 'CANCELADA')
            ->whereBetween('venda_datahora_iniciada', [$inicio, $fim])
            ->count();
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
                ->chart($this->sparklineFaturamento($inicio, $fim))
                ->color('success'),

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
     *
     * @return array<int, float>
     */
    private function sparklineFaturamento(Carbon $inicio, Carbon $fim): array
    {
        return Venda::query()
            ->where('venda_status', 'FINALIZADA')
            ->whereBetween('venda_datahora_finalizada', [$inicio, $fim])
            ->selectRaw('DATE(venda_datahora_finalizada) as dia, SUM(venda_valor_total) as total')
            ->groupBy('dia')
            ->orderBy('dia')
            ->pluck('total')
            ->map(fn ($v) => (float) $v)
            ->all();
    }
}
