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
        return 3;
    }

    protected function getStats(): array
    {
        $totais = (new RelatorioTaxaServicoService($this->pageFilters ?? []))->totais();

        return [
            Stat::make('Taxa de serviço', $this->brl($totais['taxa']))
                ->description("{$totais['garcons']} garçom(ns)")
                ->descriptionIcon('heroicon-m-user-group')
                ->color('success'),

            Stat::make('Consumo com taxa', $this->brl($totais['consumo']))
                ->description('Base de cálculo da taxa')
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('primary'),

            Stat::make('Mesas atendidas', (string) $totais['mesas'])
                ->description('Sessões de mesa com taxa')
                ->descriptionIcon('heroicon-m-squares-2x2')
                ->color('gray'),
        ];
    }
}
