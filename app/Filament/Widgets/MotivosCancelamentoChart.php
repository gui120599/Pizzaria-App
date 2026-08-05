<?php

namespace App\Filament\Widgets;

use App\Enums\MotivoCancelamentoEnum;
use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\DB;

class MotivosCancelamentoChart extends ChartWidget
{
    use HasWidgetShield;
    use InteractsComPeriodo;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Motivos de cancelamento';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        [$inicio, $fim] = $this->periodo();

        // DB::table evita o cast de enum em pedido_motivo_cancelamento.
        // Agrupa pela data do cancelamento (quando a perda ocorreu).
        $porMotivo = DB::table('pedidos')
            ->where('pedido_status', 'CANCELADO')
            ->whereBetween('pedido_datahora_cancelado', [$inicio, $fim])
            ->selectRaw('pedido_motivo_cancelamento as motivo, COUNT(*) as total')
            ->groupBy('motivo')
            ->orderByDesc('total')
            ->pluck('total', 'motivo');

        $labels = [];
        $dados = [];
        foreach ($porMotivo as $motivo => $total) {
            $labels[] = $motivo
                ? (MotivoCancelamentoEnum::tryFrom($motivo)?->label() ?? $motivo)
                : 'Não informado';
            $dados[] = (int) $total;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Cancelamentos',
                    'data' => $dados,
                    'backgroundColor' => '#ef4444',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => ['beginAtZero' => true],
            ],
        ];
    }
}
