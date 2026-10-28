<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\RelatorioInfluenciadoresWidget;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Resultado do programa de influenciadores no período (Admin e Gerente).
 * Mesmo padrão dos demais relatórios: Dashboard + filtro de período + widget.
 */
class RelatorioInfluenciadores extends BaseDashboard
{
    use HasFiltersForm;
    use HasPageShield;

    protected static string $routePath = '/relatorio-influenciadores';

    protected static ?string $title = 'Relatório de Influenciadores';

    protected static ?string $navigationLabel = 'Relatório de Influenciadores';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static UnitEnum|string|null $navigationGroup = 'Pessoas';

    protected static ?int $navigationSort = 29;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Período')
                ->description('Cliques, pedidos e movimentos da carteira no período; o saldo é o de hoje.')
                ->icon('heroicon-o-funnel')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    DatePicker::make('inicio')
                        ->label('Início')
                        ->default(now()->startOfMonth())
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->maxDate(now()),

                    DatePicker::make('fim')
                        ->label('Fim')
                        ->default(now())
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->maxDate(now()),
                ]),
        ]);
    }

    public function getWidgets(): array
    {
        return [RelatorioInfluenciadoresWidget::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
