<?php

namespace App\Filament\Influenciador\Pages;

use App\Filament\Influenciador\Widgets\ComissaoStatsOverview;
use App\Filament\Influenciador\Widgets\PedidosPorDiaChart;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\AccountWidget;

class Dashboard extends BaseDashboard
{
    public function getColumns(): int|array
    {
        return 1;
    }

    public function getWidgets(): array
    {
        return [
            AccountWidget::class,
            ComissaoStatsOverview::class,
            PedidosPorDiaChart::class,
        ];
    }
}
