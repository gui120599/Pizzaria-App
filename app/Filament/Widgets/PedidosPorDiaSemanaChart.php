<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use App\Models\Pedido;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class PedidosPorDiaSemanaChart extends ChartWidget
{
    use HasWidgetShield;
    use InteractsComPeriodo;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Pedidos por dia da semana';

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        [$inicio, $fim] = $this->periodo();

        // DAYOFWEEK do MySQL: 1=Domingo ... 7=Sábado.
        $porDia = Pedido::query()
            ->where('pedido_status', '!=', 'INICIADO')
            ->whereBetween('pedido_datahora_abertura', [$inicio, $fim])
            ->selectRaw('DAYOFWEEK(pedido_datahora_abertura) as dia, COUNT(*) as total')
            ->groupBy('dia')
            ->pluck('total', 'dia');

        $nomes = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
        $dados = [];
        for ($d = 1; $d <= 7; $d++) {
            $dados[] = (int) ($porDia[$d] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Pedidos',
                    'data' => $dados,
                    'backgroundColor' => '#3b82f6',
                ],
            ],
            'labels' => $nomes,
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
