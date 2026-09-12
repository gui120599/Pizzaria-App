<?php

namespace App\Filament\Widgets;

use App\Enums\TipoLancamento;
use App\Filament\Widgets\Concerns\InteractsComFiltrosContasPagarReceber;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class ContasPagarReceberTopFavorecidosWidget extends Widget
{
    use HasWidgetShield;
    use InteractsComFiltrosContasPagarReceber;
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.contas-pagar-receber-top-favorecidos';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $service = $this->relatorioService();

        return [
            'topFornecedores' => $service->topFavorecidos(TipoLancamento::Pagar, 10),
            'topClientes' => $service->topFavorecidos(TipoLancamento::Receber, 10),
        ];
    }
}
