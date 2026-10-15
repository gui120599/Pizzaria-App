<?php

namespace App\Filament\Widgets;

use App\Filament\Components\ContaFinanceiraSelect;
use App\Filament\Resources\MovimentacoesFinanceiras\MovimentacaoFinanceiraResource;
use App\Models\ContaFinanceira;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Saldo atual de cada conta financeira ativa (Caixa PDV: dinheiro da gaveta da sessão aberta). */
class SaldosContasWidget extends StatsOverviewWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 0;

    protected ?string $heading = 'Saldos das contas';

    protected function getStats(): array
    {
        return ContaFinanceira::query()->ordenadas()->get()
            ->map(function (ContaFinanceira $conta): Stat {
                $url = MovimentacaoFinanceiraResource::getUrl('index', ['filters' => ['conta_financeira_id' => ['value' => $conta->id]]]);

                if ($conta->ehCaixaPdv()) {
                    return Stat::make($conta->nome, str(ContaFinanceiraSelect::rotulo($conta))->after(' — ')->toString())
                        ->description('Dinheiro na gaveta')
                        ->icon($conta->tipo->getIcon())
                        ->url($url);
                }

                $saldo = (float) $conta->saldo_atual;

                return Stat::make($conta->nome, 'R$ '.number_format($saldo, 2, ',', '.'))
                    ->description($conta->tipo->getLabel())
                    ->icon($conta->tipo->getIcon())
                    ->color($saldo < 0 ? 'danger' : 'success')
                    ->url($url);
            })
            ->all();
    }
}
