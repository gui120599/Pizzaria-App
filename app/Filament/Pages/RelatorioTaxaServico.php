<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\TaxaServicoDetalhamentoWidget;
use App\Filament\Widgets\TaxaServicoPorGarcomWidget;
use App\Filament\Widgets\TaxaServicoStatsOverview;
use App\Models\User;
use App\Services\RelatorioTaxaServicoService;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Taxa de serviço por garçom no período — mesmo padrão de
 * RelatorioContasPagarReceber (Dashboard + HasFiltersForm + widgets lendo
 * $pageFilters + "Imprimir"). Regra em App\Services\RelatorioTaxaServicoService.
 */
class RelatorioTaxaServico extends BaseDashboard
{
    use HasFiltersForm;
    use HasPageShield;

    protected static string $routePath = '/relatorio-taxa-servico';

    protected static ?string $title = 'Taxa de Serviço por Garçom';

    protected static ?string $navigationLabel = 'Relatório Taxa de Serviço';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 11;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Filtros')
                ->description('Vendas finalizadas no período.')
                ->icon('heroicon-o-funnel')
                ->columns(4)
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

                    Select::make('garcom_id')
                        ->label('Garçom')
                        ->options(fn (): array => User::role(User::GARCOM_PANEL_ROLES)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->placeholder('Todos')
                        ->searchable()
                        ->native(false),

                    Select::make('atribuicao')
                        ->label('Atribuir a taxa')
                        ->options(RelatorioTaxaServicoService::ATRIBUICOES)
                        ->default(RelatorioTaxaServicoService::ATRIBUICAO_RODADA)
                        ->selectablePlaceholder(false)
                        ->native(false),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('imprimir')
                ->label('Imprimir')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('primary')
                ->url(fn (): string => route('relatorios.taxa_servico.imprimir', ['filters' => $this->filters ?? []]))
                ->openUrlInNewTab(),
        ];
    }

    public function getWidgets(): array
    {
        return [
            TaxaServicoStatsOverview::class,
            TaxaServicoPorGarcomWidget::class,
            TaxaServicoDetalhamentoWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
