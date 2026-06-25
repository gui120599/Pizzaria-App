<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\FaturamentoPorDiaChart;
use App\Filament\Widgets\FinanceiroStatsOverview;
use App\Filament\Widgets\FormasPagamentoChart;
use App\Filament\Widgets\TopProdutosVendidos;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class FinanceiroDashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string $routePath = '/financeiro';

    protected static ?string $title = 'Painel Financeiro';

    protected static ?string $navigationLabel = 'Painel Financeiro';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

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
            FinanceiroStatsOverview::class,
            FaturamentoPorDiaChart::class,
            FormasPagamentoChart::class,
            TopProdutosVendidos::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
