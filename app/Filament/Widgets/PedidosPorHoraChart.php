<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use App\Models\Pedido;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class PedidosPorHoraChart extends ChartWidget
{
    use HasWidgetShield;
    use InteractsComPeriodo;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Pedidos por hora do dia';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        [$inicio, $fim] = $this->periodo();

        $porHora = Pedido::query()
            ->where('pedido_status', '!=', 'INICIADO')
            ->whereBetween('pedido_datahora_abertura', [$inicio, $fim])
            ->selectRaw('HOUR(pedido_datahora_abertura) as hora, COUNT(*) as total')
            ->groupBy('hora')
            ->pluck('total', 'hora');

        // Normaliza as 24 horas para o gráfico ficar contínuo.
        $dados = [];
        $labels = [];
        for ($h = 0; $h < 24; $h++) {
            $labels[] = str_pad((string) $h, 2, '0', STR_PAD_LEFT).'h';
            $dados[] = (int) ($porHora[$h] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Pedidos',
                    'data' => $dados,
                    'backgroundColor' => '#f59e0b',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'y' => ['beginAtZero' => true],
            ],
        ];
    }
}
