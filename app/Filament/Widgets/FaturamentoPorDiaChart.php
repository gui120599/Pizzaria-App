<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use App\Models\Venda;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class FaturamentoPorDiaChart extends ChartWidget
{
    use InteractsComPeriodo;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Faturamento por dia';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        [$inicio, $fim] = $this->periodo();

        $rows = Venda::query()
            ->where('venda_status', 'FINALIZADA')
            ->whereBetween('venda_datahora_finalizada', [$inicio, $fim])
            ->selectRaw('DATE(venda_datahora_finalizada) as dia, SUM(venda_valor_total) as total')
            ->groupBy('dia')
            ->orderBy('dia')
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Faturamento (R$)',
                    'data' => $rows->pluck('total')->map(fn ($v) => (float) $v)->all(),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'fill' => 'start',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $rows->pluck('dia')
                ->map(fn ($d) => Carbon::parse($d)->format('d/m'))
                ->all(),
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
