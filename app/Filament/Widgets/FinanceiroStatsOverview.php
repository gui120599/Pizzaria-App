<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use App\Support\IndicadoresVendas;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Carbon\Carbon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

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
        [$inicioAnterior, $fimAnterior] = $this->periodoAnterior();

        $atual = $this->indicadores($inicio, $fim);
        $anterior = $this->indicadores($inicioAnterior, $fimAnterior);

        // CMV ideal em restaurante costuma ficar entre 28% e 35%.
        $cmvCor = match (true) {
            $atual['cmv_pct'] <= 35 => 'success',
            $atual['cmv_pct'] <= 40 => 'warning',
            default => 'danger',
        };

        return [
            Stat::make('Faturamento', $this->brl($atual['faturamento']))
                ->description($this->descricaoComComparativo("{$atual['vendas']} vendas finalizadas", $atual['faturamento'], $anterior['faturamento']))
                ->descriptionIcon('heroicon-m-banknotes')
                ->chart($this->sparklineFaturamento($inicio, $fim, $this->filtrandoPorProduto()))
                ->color('success'),

            Stat::make('Qtd. total de produtos', number_format($atual['qtd_produtos'], 0, ',', '.'))
                ->description($this->descricaoComComparativo('Unidades vendidas no período', $atual['qtd_produtos'], $anterior['qtd_produtos']))
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('info'),

            Stat::make('Ticket médio', $this->brl($atual['ticket']))
                ->description($this->descricaoComComparativo('Faturamento ÷ nº de vendas', $atual['ticket'], $anterior['ticket']))
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('info'),

            Stat::make('CMV', $this->brl($atual['cmv']))
                ->description($this->descricaoComComparativo("{$this->pct($atual['cmv_pct'])} do faturamento", $atual['cmv'], $anterior['cmv']))
                ->descriptionIcon('heroicon-m-cube')
                ->color($cmvCor),

            Stat::make('Margem bruta', $this->brl($atual['margem']))
                ->description($this->descricaoComComparativo("{$this->pct($atual['margem_pct'])} de margem", $atual['margem'], $anterior['margem']))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color($atual['margem_pct'] >= 60 ? 'success' : ($atual['margem_pct'] >= 50 ? 'warning' : 'danger')),

            Stat::make('Descontos concedidos', $this->brl($atual['descontos']))
                ->description($this->descricaoComComparativo("{$this->pct($atual['desconto_pct'])} sobre o bruto", $atual['descontos'], $anterior['descontos']))
                ->descriptionIcon('heroicon-m-tag')
                ->color($atual['descontos'] > 0 ? 'warning' : 'gray'),

            Stat::make('Cancelamentos', (string) $atual['canceladas'])
                ->description($this->descricaoComComparativo("{$this->pct($atual['taxa_cancelamento'])} das vendas", $atual['canceladas'], $anterior['canceladas']))
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($atual['taxa_cancelamento'] >= 5 ? 'danger' : 'gray'),
        ];
    }

    /**
     * Indicadores do período (ver App\Support\IndicadoresVendas, que o
     * conector "PizzariaAPP Gestão" do Claude também usa: o número é o mesmo).
     *
     * @return array<string, float|int>
     */
    private function indicadores(Carbon $inicio, Carbon $fim): array
    {
        return (new IndicadoresVendas($this->pageFilters ?? []))->indicadores($inicio, $fim);
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
