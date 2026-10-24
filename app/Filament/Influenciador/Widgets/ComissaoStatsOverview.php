<?php

namespace App\Filament\Influenciador\Widgets;

use App\Enums\StatusComissaoEnum;
use App\Models\CliqueReferencia;
use App\Models\Comissao;
use App\Models\Cupom;
use App\Models\Influenciador;
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
        /** @var Influenciador $influenciador */
        $influenciador = Auth::guard('influenciador')->user();
        $influenciadorId = $influenciador->id;

        // Só pedidos que valem: o rascunho do cardápio (INICIADO) e o cancelado ficam de fora.
        $pedidosDoMes = Pedido::whereHas('cupom', fn ($q) => $q->where('cupom_influenciador_id', $influenciadorId))
            ->whereNotIn('pedido_status', ['INICIADO', 'CANCELADO'])
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);

        $totalPedidosMes = (clone $pedidosDoMes)->count();
        $vendasGeradasMes = (float) (clone $pedidosDoMes)->sum('pedido_valor_total');

        $comissaoPendente = (float) Comissao::where('comissao_influenciador_id', $influenciadorId)
            ->where('comissao_status', StatusComissaoEnum::PENDENTE->value)
            ->sum('comissao_valor');

        $saldo = app(CarteiraInfluenciadorService::class)->saldo($influenciadorId);

        $cliquesMes = CliqueReferencia::where('clique_influenciador_id', $influenciadorId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $cupomMaisUsado = Cupom::where('cupom_influenciador_id', $influenciadorId)
            ->withCount('pedidosValidos')
            ->orderByDesc('pedidos_validos_count')
            ->first();

        return [
            Stat::make('Pedidos no Mês', (string) $totalPedidosMes)
                ->description('Gerados pelos seus cupons')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('success'),

            Stat::make('Vendas Geradas', 'R$ '.number_format($vendasGeradasMes, 2, ',', '.'))
                ->description('Pedidos do mês, sem rascunhos e cancelados')
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

            Stat::make('Cliques no Link (mês)', (string) $cliquesMes)
                ->description('Acessos pelo seu link rastreável')
                ->descriptionIcon('heroicon-m-cursor-arrow-rays')
                ->color('gray'),

            Stat::make('Cupom Mais Usado', $cupomMaisUsado?->cupom_codigo ?? '—')
                ->description($cupomMaisUsado && $cupomMaisUsado->pedidos_validos_count > 0 ? "{$cupomMaisUsado->pedidos_validos_count} uso(s)" : 'Nenhum cupom usado ainda')
                ->descriptionIcon('heroicon-m-ticket')
                ->color('gray'),

            Stat::make('Situação do Cadastro', $influenciador->influenciador_status->label())
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),
        ];
    }
}
