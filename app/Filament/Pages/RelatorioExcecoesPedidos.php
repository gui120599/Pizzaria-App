<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ExcecoesCancelamentosPorMotivoWidget;
use App\Filament\Widgets\ExcecoesCancelamentosPorUsuarioWidget;
use App\Filament\Widgets\ExcecoesEntreguesNaoPagosWidget;
use App\Filament\Widgets\ExcecoesItensRemovidosWidget;
use App\Filament\Widgets\ExcecoesLancamentosPorUsuarioWidget;
use App\Filament\Widgets\ExcecoesPedidosExcluidosWidget;
use App\Filament\Widgets\ExcecoesPedidosStatsOverview;
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
 * Exceções e perdas dos pedidos (cancelamentos, itens removidos, descontos,
 * cortesias, exclusões e entregas sem pagamento). Mesmo padrão de
 * RelatorioTaxaServico: Dashboard + HasFiltersForm + widgets lendo
 * $pageFilters. Regra em App\Services\RelatorioExcecoesPedidosService.
 *
 * A permission view:relatorio_excecoes_pedidos é do Gerente; o Admin passa
 * pelo Gate.
 */
class RelatorioExcecoesPedidos extends BaseDashboard
{
    use HasFiltersForm;
    use HasPageShield;

    protected static string $routePath = '/relatorio-excecoes-pedidos';

    protected static ?string $title = 'Exceções e Perdas';

    protected static ?string $navigationLabel = 'Exceções e Perdas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static UnitEnum|string|null $navigationGroup = 'Indicadores';

    protected static ?int $navigationSort = 10;

    public function getSubheading(): ?string
    {
        return 'Cancelamentos, itens removidos, cortesias e exclusões pela data do evento; descontos e pedidos sem pagamento pela abertura do pedido. Rascunhos não entram.';
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Período')
                ->description('Filtra todos os indicadores abaixo e define o período anterior usado na comparação.')
                ->icon('heroicon-o-calendar-days')
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
        return [
            ExcecoesPedidosStatsOverview::class,
            ExcecoesCancelamentosPorMotivoWidget::class,
            ExcecoesCancelamentosPorUsuarioWidget::class,
            ExcecoesItensRemovidosWidget::class,
            ExcecoesPedidosExcluidosWidget::class,
            ExcecoesEntreguesNaoPagosWidget::class,
            ExcecoesLancamentosPorUsuarioWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
