<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\FechamentoCaixaBandeirasWidget;
use App\Filament\Widgets\FechamentoCaixaConferenciaMaquininhasWidget;
use App\Filament\Widgets\FechamentoCaixaConferenciaWidget;
use App\Filament\Widgets\FechamentoCaixaDreWidget;
use App\Filament\Widgets\FechamentoCaixaFluxoCaixaWidget;
use App\Filament\Widgets\FechamentoCaixaFormasPagamentoWidget;
use App\Filament\Widgets\FechamentoCaixaMaquininhasWidget;
use App\Filament\Widgets\FechamentoCaixaMovimentacoesWidget;
use App\Filament\Widgets\FechamentoCaixaPorMaquininhaWidget;
use App\Filament\Widgets\FechamentoCaixaPorOperadoraWidget;
use App\Filament\Widgets\FechamentoCaixaPrevisaoRecebimentoWidget;
use App\Filament\Widgets\FechamentoCaixaStatsOverview;
use App\Models\Caixa;
use App\Models\SessaoCaixa;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Fechamento de caixa com visão de analista: DRE, fluxo por forma de
 * pagamento, maquininhas × bandeira com a taxa abatida, previsão de
 * recebimento, movimentações e conferência. Mesmo padrão de
 * RelatorioTaxaServico; regra em App\Services\RelatorioFechamentoCaixaService.
 */
class RelatorioFechamentoCaixa extends BaseDashboard
{
    use HasFiltersForm;
    use HasPageShield;

    protected static string $routePath = '/relatorio-fechamento-caixa';

    protected static ?string $title = 'Relatório de Fechamento de Caixa';

    protected static ?string $navigationLabel = 'Relatório Fechamento de Caixa';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 13;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Filtros')
                ->description('Vendas finalizadas no período. Selecionando sessões, valem só as vendas delas e o período é ignorado.')
                ->icon('heroicon-o-funnel')
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    DatePicker::make('inicio')
                        ->label('Início')
                        ->default(now()->startOfMonth())
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
                        ->options(fn (Get $get): array => self::opcoesSessoes(
                            SessaoCaixa::query()
                                ->when($get('caixa_id'), fn (Builder $query, $caixaId) => $query->where('sessaocaixa_caixa_id', $caixaId))
                                ->latest('sessaocaixa_data_hora_abertura')
                                ->limit(50),
                        ))
                        ->getSearchResultsUsing(fn (string $search, Get $get): array => self::opcoesSessoes(
                            SessaoCaixa::query()
                                ->when($get('caixa_id'), fn (Builder $query, $caixaId) => $query->where('sessaocaixa_caixa_id', $caixaId))
                                ->where(fn (Builder $query) => $query
                                    ->where('id', (int) $search)
                                    ->orWhereHas('user', fn (Builder $query) => $query->where('name', 'like', "%{$search}%")))
                                ->latest('sessaocaixa_data_hora_abertura')
                                ->limit(50),
                        ))
                        ->getOptionLabelsUsing(fn (array $values): array => self::opcoesSessoes(SessaoCaixa::query()->whereKey($values)))
                        ->placeholder('Todas do período'),
                ]),
        ]);
    }

    /**
     * @param  Builder<SessaoCaixa>  $query
     * @return array<int, string>
     */
    private static function opcoesSessoes(Builder $query): array
    {
        return $query
            ->with(['caixa' => fn ($query) => $query->withTrashed(), 'user'])
            ->get()
            ->mapWithKeys(fn (SessaoCaixa $sessao): array => [
                $sessao->id => "#{$sessao->id} — ".($sessao->caixa?->caixa_nome ?? 'Caixa')
                    .' — '.$sessao->sessaocaixa_data_hora_abertura?->format('d/m H:i')
                    .($sessao->user ? " ({$sessao->user->name})" : ''),
            ])
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('imprimir')
                ->label('Imprimir')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('primary')
                ->url(fn (): string => route('relatorios.fechamento_caixa.imprimir', ['filters' => $this->filters ?? []]))
                ->openUrlInNewTab(),
        ];
    }

    public function getWidgets(): array
    {
        return [
            FechamentoCaixaStatsOverview::class,
            FechamentoCaixaDreWidget::class,
            FechamentoCaixaFluxoCaixaWidget::class,
            FechamentoCaixaFormasPagamentoWidget::class,
            FechamentoCaixaPorOperadoraWidget::class,
            FechamentoCaixaPorMaquininhaWidget::class,
            FechamentoCaixaMaquininhasWidget::class,
            FechamentoCaixaBandeirasWidget::class,
            FechamentoCaixaPrevisaoRecebimentoWidget::class,
            FechamentoCaixaMovimentacoesWidget::class,
            FechamentoCaixaConferenciaWidget::class,
            FechamentoCaixaConferenciaMaquininhasWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
