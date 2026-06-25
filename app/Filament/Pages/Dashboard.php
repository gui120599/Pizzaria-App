<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;

/**
 * Dashboard inicial. Lista os widgets explicitamente para que os widgets
 * financeiros (descobertos automaticamente) NÃO apareçam aqui — eles são
 * exclusivos do FinanceiroDashboard.
 */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            AccountWidget::class,
            FilamentInfoWidget::class,
        ];
    }
}
