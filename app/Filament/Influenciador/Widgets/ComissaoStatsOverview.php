<?php

namespace App\Filament\Influenciador\Widgets;

use App\Enums\StatusComissaoEnum;
use App\Models\Comissao;
use App\Models\Cupom;
use App\Models\Pedido;
use App\Services\CarteiraInfluenciadorService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class ComissaoStatsOverview extends BaseWidget
{
    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $influenciadorId = Auth::guard('influenciador')->id();

        $pedidosDoMes = Pedido::whereHas('cupom', fn ($q) => $q->where('cupom_influenciador_id', $influenciadorId))
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);

        $totalPedidosMes = (clone $pedidosDoMes)->count();
        $faturamentoMes = (float) (clone $pedidosDoMes)->sum('pedido_valor_total');

        $comissaoPendente = (float) Comissao::where('comissao_influenciador_id', $influenciadorId)
            ->where('comissao_status', StatusComissaoEnum::PENDENTE->value)
            ->sum('comissao_valor');

        $saldo = app(CarteiraInfluenciadorService::class)->saldo($influenciadorId);

        $cupomMaisUsado = Cupom::where('cupom_influenciador_id', $influenciadorId)
            ->withCount('pedidos')
            ->orderByDesc('pedidos_count')
            ->first();

        return [
            Stat::make('Pedidos no Mês', (string) $totalPedidosMes)
                ->description('Gerados pelos seus cupons')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('success'),

            Stat::make('Faturamento Gerado', 'R$ '.number_format($faturamentoMes, 2, ',', '.'))
                ->description('Somatório dos pedidos do mês')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('success'),

            Stat::make('Saldo da Carteira', 'R$ '.number_format($saldo, 2, ',', '.'))
                ->description('Disponível para resgatar em Pix ou consumir em pizza')
                ->descriptionIcon('heroicon-m-wallet')
                ->color('success'),

            Stat::make('Comissão Pendente', 'R$ '.number_format($comissaoPendente, 2, ',', '.'))
                ->description('Estimada, até o pedido ser pago')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),

            Stat::make('Cupom Mais Usado', $cupomMaisUsado?->cupom_codigo ?? '—')
                ->description($cupomMaisUsado ? "{$cupomMaisUsado->pedidos_count} uso(s)" : 'Nenhum cupom usado ainda')
                ->descriptionIcon('heroicon-m-ticket')
                ->color('gray'),
        ];
    }
}
