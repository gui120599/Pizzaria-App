<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\FaturamentoPorDiaChart;
use App\Filament\Widgets\FaturamentoPorTipoEntregaChart;
use App\Filament\Widgets\FinanceiroStatsOverview;
use App\Filament\Widgets\FormasPagamentoChart;
use App\Filament\Widgets\ProdutosVendidosPorPeriodoChart;
use App\Filament\Widgets\TopProdutosVendidos;
use App\Models\Categoria;
use App\Models\OpcoesEntregas;
use App\Models\Produto;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class FinanceiroDashboard extends BaseDashboard
{
    use HasFiltersForm;
    use HasPageShield;

    protected static string $routePath = '/financeiro';

    protected static ?string $title = 'Painel Financeiro';

    protected static ?string $navigationLabel = 'Painel Financeiro';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static UnitEnum|string|null $navigationGroup = 'Indicadores';

    protected static ?int $navigationSort = -1;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Período')
                ->description('Filtra todos os indicadores abaixo.')
                ->icon('heroicon-o-calendar-days')
                ->columns(2)
                ->columnSpan(1)
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

            Section::make('Filtros')
                ->description('Refina todos os indicadores por tipo de entrega, categoria e produto.')
                ->icon('heroicon-o-funnel')
                ->columns(3)
                ->columnSpan(3)
                ->schema([
                    Select::make('tipos_entrega')
                        ->label('Tipo de entrega')
                        ->options(fn () => OpcoesEntregas::orderBy('opcaoentrega_nome')->pluck('opcaoentrega_nome', 'id'))
                        ->multiple()
                        ->native(false)
                        ->preload(),

                    Select::make('categorias')
                        ->label('Categorias')
                        ->options(fn () => Categoria::orderBy('categoria_nome')->pluck('categoria_nome', 'id'))
                        ->multiple()
                        ->searchable()
                        ->native(false)
                        ->preload(),

                    Select::make('produtos')
                        ->label('Produtos')
                        ->options(fn () => Produto::orderBy('produto_descricao')->pluck('produto_descricao', 'id'))
                        ->multiple()
                        ->searchable()
                        ->native(false)
                        ->preload(),
                ]),
        ])->columns(4);
    }

    public function getWidgets(): array
    {
        return [
            FinanceiroStatsOverview::class,
            FaturamentoPorDiaChart::class,
            ProdutosVendidosPorPeriodoChart::class,
            FaturamentoPorTipoEntregaChart::class,
            FormasPagamentoChart::class,
            TopProdutosVendidos::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
