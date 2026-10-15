<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\CustoPessoalPeriodoWidget;
use App\Filament\Widgets\InconsistenciasPendentesWidget;
use App\Filament\Widgets\TrabalhandoAgoraWidget;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Quem está trabalhando, o que falta corrigir antes do fechamento e o custo de pessoal do mês. */
class PontoDashboard extends BaseDashboard
{
    protected static string $routePath = '/ponto';

    protected static ?string $title = 'Painel do Ponto';

    protected static ?string $navigationLabel = 'Painel do Ponto';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static UnitEnum|string|null $navigationGroup = 'Ponto e Repasses';

    protected static ?int $navigationSort = 0;

    public static function canAccess(): bool
    {
        return Auth::user()?->can('view_espelho:ponto') ?? false;
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }

    public function getWidgets(): array
    {
        return [
            CustoPessoalPeriodoWidget::class,
            TrabalhandoAgoraWidget::class,
            InconsistenciasPendentesWidget::class,
        ];
    }
}
