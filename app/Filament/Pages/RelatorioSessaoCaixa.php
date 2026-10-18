<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\FechamentoCaixaConferenciaMaquininhasWidget;
use App\Filament\Widgets\FechamentoCaixaConferenciaWidget;
use App\Filament\Widgets\FechamentoCaixaFluxoCaixaWidget;
use App\Filament\Widgets\SessaoCaixaCortesiasWidget;
use App\Filament\Widgets\SessaoCaixaFiadoWidget;
use App\Filament\Widgets\SessaoCaixaStatsOverview;
use App\Filament\Widgets\SessaoCaixaVendasWidget;
use App\Models\Caixa;
use App\Models\Cliente;
use App\Models\Maquininha;
use App\Models\OpcoesPagamento;
use App\Models\SessaoCaixa;
use App\Services\RelatorioSessaoCaixaService;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Venda a venda da sessão de caixa: o que foi lançado × o que o pedido
 * combinou, fiado, troco e avisos de lançamento suspeito, com a correção do
 * lançamento ali mesmo. Complementa o RelatorioFechamentoCaixa (agregado);
 * regra em App\Services\RelatorioSessaoCaixaService.
 */
class RelatorioSessaoCaixa extends BaseDashboard
{
    use HasFiltersForm;
    use HasPageShield;

    protected static string $routePath = '/relatorio-sessao-caixa';

    protected static ?string $title = 'Relatório de Sessão de Caixa';

    protected static ?string $navigationLabel = 'Relatório Sessão de Caixa';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 14;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Filtros')
                ->description('Vendas finalizadas no período. Selecionando sessões, valem só as vendas delas e o período é ignorado. Fluxo e conferência são sempre da sessão inteira.')
                ->icon('heroicon-o-funnel')
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    DatePicker::make('inicio')
                        ->label('Início')
                        ->default(now()->startOfDay())
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->maxDate(now())
                        ->disabled(fn (Get $get): bool => filled($get('sessoes'))),

                    DatePicker::make('fim')
                        ->label('Fim')
                        ->default(now())
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->maxDate(now())
                        ->disabled(fn (Get $get): bool => filled($get('sessoes'))),

                    Select::make('caixa_id')
                        ->label('Caixa')
                        ->options(fn (): array => Caixa::query()->orderBy('caixa_nome')->pluck('caixa_nome', 'id')->all())
                        ->placeholder('Todos')
                        ->native(false),

                    Select::make('sessoes')
                        ->label('Sessões de caixa')
                        ->multiple()
                        ->searchable()
                        ->options(fn (Get $get): array => RelatorioFechamentoCaixa::opcoesSessoes(
                            SessaoCaixa::query()
                                ->when($get('caixa_id'), fn (Builder $query, $caixaId) => $query->where('sessaocaixa_caixa_id', $caixaId))
                                ->latest('sessaocaixa_data_hora_abertura')
                                ->limit(50),
                        ))
                        ->getSearchResultsUsing(fn (string $search, Get $get): array => RelatorioFechamentoCaixa::opcoesSessoes(
                            SessaoCaixa::query()
                                ->when($get('caixa_id'), fn (Builder $query, $caixaId) => $query->where('sessaocaixa_caixa_id', $caixaId))
                                ->where(fn (Builder $query) => $query
                                    ->where('id', (int) $search)
                                    ->orWhereHas('user', fn (Builder $query) => $query->where('name', 'like', "%{$search}%")))
                                ->latest('sessaocaixa_data_hora_abertura')
                                ->limit(50),
                        ))
                        ->getOptionLabelsUsing(fn (array $values): array => RelatorioFechamentoCaixa::opcoesSessoes(SessaoCaixa::query()->whereKey($values)))
                        ->placeholder('Todas do período'),

                    Select::make('opcoes')
                        ->label('Formas de pagamento')
                        ->multiple()
                        ->options(fn (): array => OpcoesPagamento::withTrashed()->orderBy('opcaopag_nome')->pluck('opcaopag_nome', 'id')->all())
                        ->placeholder('Todas'),

                    Select::make('maquininha_id')
                        ->label('Maquininha')
                        ->options(fn (): array => Maquininha::withTrashed()->orderBy('nome')->pluck('nome', 'id')->all())
                        ->placeholder('Todas')
                        ->native(false),

                    Select::make('origem')
                        ->label('Origem')
                        ->options(RelatorioSessaoCaixaService::opcoesOrigem())
                        ->placeholder('Todas')
                        ->native(false),

                    Select::make('cliente_id')
                        ->label('Cliente')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Cliente::query()
                            ->where('cliente_nome', 'like', "%{$search}%")
                            ->orderBy('cliente_nome')
                            ->limit(50)
                            ->pluck('cliente_nome', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Cliente::find($value)?->cliente_nome)
                        ->placeholder('Todos'),

                    ToggleButtons::make('situacao')
                        ->label('Mostrar')
                        ->options([
                            'todas' => 'Todas',
                            RelatorioSessaoCaixaService::SITUACAO_DIVERGENCIA => 'Com aviso',
                            RelatorioSessaoCaixaService::SITUACAO_FIADO => 'Só fiado',
                        ])
                        ->default('todas')
                        ->inline()
                        ->columnSpan(2),
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
                ->url(fn (): string => route('relatorios.sessao_caixa.imprimir', ['filters' => $this->filters ?? []]))
                ->openUrlInNewTab(),
        ];
    }

    public function getWidgets(): array
    {
        return [
            SessaoCaixaStatsOverview::class,
            SessaoCaixaVendasWidget::class,
            SessaoCaixaFiadoWidget::class,
            SessaoCaixaCortesiasWidget::class,
            FechamentoCaixaFluxoCaixaWidget::class,
            FechamentoCaixaConferenciaWidget::class,
            FechamentoCaixaConferenciaMaquininhasWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
