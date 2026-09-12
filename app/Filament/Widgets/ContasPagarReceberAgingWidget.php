<?php

namespace App\Filament\Widgets;

use App\Enums\TipoLancamento;
use App\Filament\Widgets\Concerns\InteractsComFiltrosContasPagarReceber;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class ContasPagarReceberAgingWidget extends Widget
{
    use HasWidgetShield;
    use InteractsComFiltrosContasPagarReceber;
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.contas-pagar-receber-aging';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $service = $this->relatorioService();

        return [
            'agingVencidosPagar' => $service->agingVencidos(TipoLancamento::Pagar),
            'agingAVencerPagar' => $service->agingAVencer(TipoLancamento::Pagar),
            'agingVencidosReceber' => $service->agingVencidos(TipoLancamento::Receber),
            'agingAVencerReceber' => $service->agingAVencer(TipoLancamento::Receber),
        ];
    }
}
