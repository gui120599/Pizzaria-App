<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\MovimentacoesFinanceiras\MovimentacaoFinanceiraResource;
use App\Models\ContaFinanceira;
use App\Services\Financeiro\CaixaPdvBridge;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Saldo atual de cada conta financeira ativa (Caixa PDV: saldo do razão e, com sessão aberta, o dinheiro na gaveta agora). */
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
                    $saldo = (float) $conta->saldo_atual;

                    return Stat::make($conta->nome, 'R$ '.number_format($saldo, 2, ',', '.'))
                        ->description($this->descricaoDaGaveta())
                        ->icon($conta->tipo->getIcon())
                        ->color($saldo < 0 ? 'danger' : 'success')
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

    private function descricaoDaGaveta(): string
    {
        $ponte = app(CaixaPdvBridge::class);
        $abertas = $ponte->sessoesAbertas();

        if ($abertas->isEmpty()) {
            return 'Dinheiro nos caixas — sem sessão aberta';
        }

        $naGaveta = $abertas->sum(fn ($sessao): float => $ponte->dinheiroDisponivel($sessao));

        return 'Na gaveta agora: R$ '.number_format($naGaveta, 2, ',', '.');
    }
}
