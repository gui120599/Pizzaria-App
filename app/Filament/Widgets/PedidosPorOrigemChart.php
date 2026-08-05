<?php

namespace App\Filament\Widgets;

use App\Enums\PedidoOrigemEnum;
use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\DB;

class PedidosPorOrigemChart extends ChartWidget
{
    use HasWidgetShield;
    use InteractsComPeriodo;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Pedidos por canal';

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        [$inicio, $fim] = $this->periodo();

        // DB::table evita o cast de enum em pedido_origem (que viraria objeto na chave).
        $porOrigem = DB::table('pedidos')
            ->where('pedido_status', '!=', 'INICIADO')
            ->whereBetween('pedido_datahora_abertura', [$inicio, $fim])
            ->selectRaw('pedido_origem, COUNT(*) as total')
            ->groupBy('pedido_origem')
            ->orderByDesc('total')
            ->pluck('total', 'pedido_origem');

        $labels = [];
        $dados = [];
        foreach ($porOrigem as $origem => $total) {
            $labels[] = $origem
                ? (PedidoOrigemEnum::tryFrom($origem)?->label() ?? $origem)
                : 'Não informado';
            $dados[] = (int) $total;
        }

        $paleta = ['#22c55e', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#64748b'];

        return [
            'datasets' => [
                [
                    'label' => 'Pedidos',
                    'data' => $dados,
                    'backgroundColor' => array_slice($paleta, 0, max(1, count($dados))),
                ],
            ],
            'labels' => $labels,
        ];
    }
}
