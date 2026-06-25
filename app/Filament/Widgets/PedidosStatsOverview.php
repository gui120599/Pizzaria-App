<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use App\Models\ItensPedido;
use App\Models\Pedido;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class PedidosStatsOverview extends BaseWidget
{
    use InteractsComPeriodo;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Operação do período';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        [$inicio, $fim] = $this->periodo();

        // Pedidos "reais" = tudo que saiu de INICIADO (carrinho), pela abertura.
        $base = Pedido::query()
            ->where('pedido_status', '!=', 'INICIADO')
            ->whereBetween('pedido_datahora_abertura', [$inicio, $fim]);

        $totalPedidos = (clone $base)->count();
        $finalizados = (clone $base)->where('pedido_status', 'FINALIZADO')->count();
        $cancelados = (clone $base)->where('pedido_status', 'CANCELADO')->count();
        $taxaCancel = $totalPedidos > 0 ? $cancelados / $totalPedidos * 100 : 0.0;

        // Tempo médio de preparo: preparo -> pronto (minutos).
        $tPreparo = (float) (clone $base)
            ->whereNotNull('pedido_datahora_preparo')
            ->whereNotNull('pedido_datahora_pronto')
            ->whereColumn('pedido_datahora_pronto', '>=', 'pedido_datahora_preparo')
            ->avg(DB::raw('TIMESTAMPDIFF(SECOND, pedido_datahora_preparo, pedido_datahora_pronto)')) / 60;

        // Tempo médio de entrega: transporte -> entrega (subconjunto delivery).
        $tEntrega = (float) (clone $base)
            ->whereNotNull('pedido_datahora_transporte')
            ->whereNotNull('pedido_datahora_entrega')
            ->whereColumn('pedido_datahora_entrega', '>=', 'pedido_datahora_transporte')
            ->avg(DB::raw('TIMESTAMPDIFF(SECOND, pedido_datahora_transporte, pedido_datahora_entrega)')) / 60;

        // Tempo médio de ciclo: abertura -> finalizado.
        $tCiclo = (float) (clone $base)
            ->whereNotNull('pedido_datahora_finalizado')
            ->whereColumn('pedido_datahora_finalizado', '>=', 'pedido_datahora_abertura')
            ->avg(DB::raw('TIMESTAMPDIFF(SECOND, pedido_datahora_abertura, pedido_datahora_finalizado)')) / 60;

        // Itens por pedido (média) entre os pedidos do período.
        $totalItens = ItensPedido::query()
            ->join('pedidos', 'pedidos.id', '=', 'itens_pedidos.item_pedido_pedido_id')
            ->where('pedidos.pedido_status', '!=', 'INICIADO')
            ->where('itens_pedidos.item_pedido_status', 'INSERIDO')
            ->whereBetween('pedidos.pedido_datahora_abertura', [$inicio, $fim])
            ->count();
        $itensPorPedido = $totalPedidos > 0 ? $totalItens / $totalPedidos : 0.0;

        return [
            Stat::make('Pedidos', (string) $totalPedidos)
                ->description("{$finalizados} finalizados")
                ->descriptionIcon('heroicon-m-clipboard-document-list')
                ->chart($this->sparklinePedidos($inicio, $fim))
                ->color('success'),

            Stat::make('Tempo de preparo', $this->minutos($tPreparo))
                ->description('Início do preparo → pronto')
                ->descriptionIcon('heroicon-m-fire')
                ->color($tPreparo <= 20 ? 'success' : ($tPreparo <= 30 ? 'warning' : 'danger')),

            Stat::make('Tempo de entrega', $tEntrega > 0 ? $this->minutos($tEntrega) : '—')
                ->description('Saída → entrega (delivery)')
                ->descriptionIcon('heroicon-m-truck')
                ->color($tEntrega == 0 ? 'gray' : ($tEntrega <= 40 ? 'success' : ($tEntrega <= 60 ? 'warning' : 'danger'))),

            Stat::make('Tempo de ciclo', $tCiclo > 0 ? $this->minutos($tCiclo) : '—')
                ->description('Abertura → finalização')
                ->descriptionIcon('heroicon-m-clock')
                ->color('info'),

            Stat::make('Itens por pedido', number_format($itensPorPedido, 1, ',', '.'))
                ->description("{$totalItens} itens no período")
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('info'),

            Stat::make('Cancelamentos', (string) $cancelados)
                ->description("{$this->pct($taxaCancel)} dos pedidos")
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($taxaCancel >= 10 ? 'danger' : ($taxaCancel >= 5 ? 'warning' : 'gray')),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function sparklinePedidos(\Carbon\Carbon $inicio, \Carbon\Carbon $fim): array
    {
        return Pedido::query()
            ->where('pedido_status', '!=', 'INICIADO')
            ->whereBetween('pedido_datahora_abertura', [$inicio, $fim])
            ->selectRaw('DATE(pedido_datahora_abertura) as dia, COUNT(*) as total')
            ->groupBy('dia')
            ->orderBy('dia')
            ->pluck('total')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
