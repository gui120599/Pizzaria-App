<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ChecklistConformidadeStatsOverview;
use App\Filament\Widgets\ChecklistItensMaisDispensadosWidget;
use App\Filament\Widgets\ChecklistItensMaisFalhamWidget;
use App\Filament\Widgets\ChecklistsHojeWidget;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Checklists de hoje, conformidade 7/30 dias e itens que mais falham. */
class ChecklistsDashboard extends BaseDashboard
{
    use HasPageShield;

    protected static string $routePath = '/checklists';

    protected static ?string $title = 'Painel de Checklists';

    protected static ?string $navigationLabel = 'Painel de Checklists';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static UnitEnum|string|null $navigationGroup = 'Operação';

    protected static ?int $navigationSort = 1;

    public function getColumns(): int|array
    {
        return 1;
    }

    public function getWidgets(): array
    {
        return [
            ChecklistConformidadeStatsOverview::class,
            ChecklistsHojeWidget::class,
            ChecklistItensMaisFalhamWidget::class,
            ChecklistItensMaisDispensadosWidget::class,
        ];
    }
}
