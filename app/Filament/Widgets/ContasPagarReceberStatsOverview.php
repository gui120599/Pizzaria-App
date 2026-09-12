<?php

namespace App\Filament\Widgets;

use App\Enums\TipoLancamento;
use App\Filament\Widgets\Concerns\InteractsComFiltrosContasPagarReceber;
use App\Services\RelatorioContasPagarReceberService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ContasPagarReceberStatsOverview extends BaseWidget
{
    use HasWidgetShield;
    use InteractsComFiltrosContasPagarReceber;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Indicadores do período';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $service = $this->relatorioService();
        $anterior = $service->periodoAnterior();

        $pagar = $service->totaisAbertoEVencido(TipoLancamento::Pagar);
        $receber = $service->totaisAbertoEVencido(TipoLancamento::Receber);

        $pmp = $service->pmp();
        $pmr = $service->pmr();

        $saldoFaixas = $service->saldoProjetadoPorFaixa();
        $saldoProjetado = array_sum($saldoFaixas);
        $saldoAnterior = array_sum($anterior->saldoProjetadoPorFaixa());
        $variacaoSaldo = RelatorioContasPagarReceberService::variacao($saldoProjetado, $saldoAnterior);

        return [
            Stat::make('A Pagar — Em aberto', $this->brl($pagar['total_aberto']))
                ->description("Vencido: {$this->brl($pagar['total_vencido'])} ({$this->pct($pagar['percentual_vencido'])})")
                ->descriptionIcon('heroicon-m-arrow-up-circle')
                ->color('danger'),

            Stat::make('A Pagar — Vencido', $this->brl($pagar['total_vencido']))
                ->description("{$this->pct($pagar['percentual_vencido'])} do total em aberto")
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($pagar['percentual_vencido'] > 0 ? 'warning' : 'gray'),

            Stat::make('A Receber — Em aberto', $this->brl($receber['total_aberto']))
                ->description("Vencido: {$this->brl($receber['total_vencido'])} ({$this->pct($receber['percentual_vencido'])})")
                ->descriptionIcon('heroicon-m-arrow-down-circle')
                ->color('success'),

            Stat::make('Índice de inadimplência', $this->pct($receber['indice_inadimplencia'] ?? 0.0))
                ->description('Vencido sobre o total a receber em aberto')
                ->descriptionIcon('heroicon-m-face-frown')
                ->color(($receber['indice_inadimplencia'] ?? 0.0) > 10 ? 'danger' : 'warning'),

            Stat::make('Prazo Médio de Pagamento (PMP)', $this->dias($pmp))
                ->description('Dias entre lançamento e pagamento efetivo')
                ->descriptionIcon('heroicon-m-clock')
                ->color('info'),

            Stat::make('Prazo Médio de Recebimento (PMR)', $this->dias($pmr))
                ->description('Dias entre lançamento e recebimento efetivo')
                ->descriptionIcon('heroicon-m-clock')
                ->color('info'),

            Stat::make('Ciclo financeiro', $this->dias($pmr - $pmp))
                ->description('PMR − PMP: dias que a operação financia sozinha')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color(($pmr - $pmp) > 0 ? 'warning' : 'success'),

            Stat::make('Saldo projetado (Receber − Pagar)', $this->brl($saldoProjetado))
                ->description($variacaoSaldo === null
                    ? 'Sem base no período anterior'
                    : ($variacaoSaldo >= 0 ? '+' : '').$this->pct($variacaoSaldo).' vs. período anterior')
                ->descriptionIcon($saldoProjetado >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($saldoProjetado >= 0 ? 'success' : 'danger'),
        ];
    }
}
