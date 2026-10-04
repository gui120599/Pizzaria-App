<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use App\Services\RelatorioTaxaServicoService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TaxaServicoStatsOverview extends BaseWidget
{
    use HasWidgetShield;
    use InteractsComPeriodo;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Taxa de serviço no período';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $service = new RelatorioTaxaServicoService($this->pageFilters ?? []);
        $totais = $service->totais();
        $semTaxa = $service->semTaxa();

        return [
            Stat::make('Taxa de serviço (bruta)', $this->brl($totais['taxa']))
                ->description("Sobre {$this->brl($totais['consumo'])} de consumo")
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('primary'),

            Stat::make('Taxa líquida', $this->brl($totais['taxa_liquida']))
                ->description("Maquininha {$this->brl($totais['desconto_maquininha'])} · Imposto {$this->brl($totais['desconto_imposto'])}")
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Mesas atendidas', (string) $totais['mesas'])
                ->description("{$totais['garcons']} garçom(ns)")
                ->descriptionIcon('heroicon-m-user-group')
                ->color('gray'),

            Stat::make('Mesas sem taxa', (string) $semTaxa['mesas'])
                ->description("{$this->brl($semTaxa['valor'])} não cobrados")
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($semTaxa['mesas'] > 0 ? 'warning' : 'gray'),
        ];
    }
}
