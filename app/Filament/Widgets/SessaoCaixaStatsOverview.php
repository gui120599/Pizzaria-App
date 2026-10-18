<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use App\Services\RelatorioSessaoCaixaService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Indicadores do Relatório de Sessão de Caixa (vendas do recorte, com os filtros). */
class SessaoCaixaStatsOverview extends BaseWidget
{
    use HasWidgetShield;
    use InteractsComPeriodo;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Resumo';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $resumo = (new RelatorioSessaoCaixaService($this->pageFilters ?? []))->resumo();

        return [
            Stat::make('Vendido', $this->brl($resumo['total']))
                ->description("{$resumo['vendas']} vendas finalizadas")
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('primary'),

            Stat::make('Recebido nas vendas', $this->brl($resumo['recebido']))
                ->description('Sem o fiado; troco já descontado')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Fiado gerado', $this->brl($resumo['fiado_gerado']))
                ->description("Recebido de fiado nas sessões: {$this->brl($resumo['fiado_recebido'])}")
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),

            Stat::make('Troco fora do dinheiro', $this->brl($resumo['troco_fora_dinheiro']))
                ->description('Troco dado em venda paga em cartão/Pix')
                ->descriptionIcon('heroicon-m-arrow-uturn-left')
                ->color($resumo['troco_fora_dinheiro'] > 0 ? 'warning' : 'gray'),

            Stat::make('Cortesias', $this->brl($resumo['cortesias_valor']))
                ->description("{$resumo['cortesias']} itens a preço de tabela · custo {$this->brl($resumo['cortesias_custo'])}")
                ->descriptionIcon('heroicon-m-gift')
                ->color($resumo['cortesias'] > 0 ? 'warning' : 'gray'),

            Stat::make('Vendas com aviso', (string) $resumo['com_aviso'])
                ->description('Forma diferente do pedido, troco fora do dinheiro ou fiado de pedido pago')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($resumo['com_aviso'] > 0 ? 'danger' : 'success'),
        ];
    }
}
