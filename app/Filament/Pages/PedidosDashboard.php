<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\MotivosCancelamentoChart;
use App\Filament\Widgets\PedidosPorDiaSemanaChart;
use App\Filament\Widgets\PedidosPorHoraChart;
use App\Filament\Widgets\PedidosPorOrigemChart;
use App\Filament\Widgets\PedidosStatsOverview;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class PedidosDashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string $routePath = '/operacional';

    protected static ?string $title = 'Painel Operacional';

    protected static ?string $navigationLabel = 'Painel Operacional';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static UnitEnum|string|null $navigationGroup = 'Indicadores';

    protected static ?int $navigationSort = -1;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Período')
                ->description('Filtra todos os indicadores abaixo.')
                ->icon('heroicon-o-calendar-days')
                ->columns(2)
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
        return [
            PedidosStatsOverview::class,
            PedidosPorHoraChart::class,
            PedidosPorDiaSemanaChart::class,
            PedidosPorOrigemChart::class,
            MotivosCancelamentoChart::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
