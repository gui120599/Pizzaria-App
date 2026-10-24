<?php

namespace App\Filament\Influenciador\Widgets;

use App\Models\Pedido;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

class PedidosPorDiaChart extends ChartWidget
{
    protected ?string $heading = 'Pedidos por dia (últimos 30 dias)';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $influenciadorId = Auth::guard('influenciador')->id();
        $inicio = now()->subDays(29)->startOfDay();

        $rows = Pedido::whereHas('cupom', fn ($q) => $q->where('cupom_influenciador_id', $influenciadorId))
            ->where('created_at', '>=', $inicio)
            ->selectRaw('DATE(created_at) as dia, COUNT(*) as total')
            ->groupBy('dia')
            ->orderBy('dia')
            ->pluck('total', 'dia');

        $labels = [];
        $data = [];

        for ($cursor = $inicio->copy(); $cursor->lte(now()); $cursor->addDay()) {
            $chave = $cursor->toDateString();
            $labels[] = $cursor->format('d/m');
            $data[] = (int) ($rows[$chave] ?? 0);
        }

        return [
            'datasets' => [[
                'label' => 'Pedidos',
                'data' => $data,
                'borderColor' => '#10b981',
                'backgroundColor' => 'rgba(16, 185, 129, 0.15)',
                'fill' => 'start',
                'tension' => 0.3,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
