<?php

namespace App\Filament\Pages;

use App\Enums\FormaPagamento;
use App\Filament\Widgets\ContasPagarReceberAgingWidget;
use App\Filament\Widgets\ContasPagarReceberDetalhamentoWidget;
use App\Filament\Widgets\ContasPagarReceberStatsOverview;
use App\Filament\Widgets\ContasPagarReceberTopFavorecidosWidget;
use App\Models\Cliente;
use App\Models\PlanoDespesa;
use App\Models\PlanoReceita;
use App\Models\Prestador;
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
use UnitEnum;

/**
 * Tela de filtros + indicadores de Contas a Pagar/Receber. Mesmo padrão de
 * App\Filament\Pages\FinanceiroDashboard (Dashboard + HasFiltersForm + widgets
 * lendo $pageFilters) — a persistência de filtros na URL vem de graça do
 * Filament\Pages\Dashboard\Concerns\HasFilters (propriedade $filters com #[Url]).
 *
 * Todos os Select ligados a enum usam options() manuais (string => label) em vez
 * de ->options(EnumClass::class): esse segundo formato faz o Filament castar o
 * estado pra instância do enum (ver App\Filament\Resources\Lancamentos\Schemas\
 * LancamentoForm::ehTipo()), o que não serializa bem no array $filters
 * persistido na URL nem bate com o formato esperado por RelatorioContasPagarReceberService.
 */
class RelatorioContasPagarReceber extends BaseDashboard
{
    use HasFiltersForm;
    use HasPageShield;

    protected static string $routePath = '/relatorio-contas-pagar-receber';

    protected static ?string $title = 'Contas a Pagar e a Receber';

    protected static ?string $navigationLabel = 'Relatório A Pagar/Receber';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 10;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Tipo e status')
                ->columns(3)
                ->columnSpan(2)
                ->schema([
                    Select::make('tipo')
                        ->label('Tipo de lançamento')
                        ->options([
                            'pagar' => 'A Pagar',
                            'receber' => 'A Receber',
                        ])
                        ->placeholder('Ambos')
                        ->native(false),
                    Select::make('status')
                        ->label('Status')
                        ->options([
                            'pago' => 'Pago',
                            'em_aberto' => 'Em aberto',
                            'vencido' => 'Vencido',
                            'a_vencer' => 'A vencer',
                        ])
                        ->placeholder('Todos')
                        ->native(false)
                        ->live(),
                    Select::make('a_vencer_dias')
                        ->label('Janela (a vencer)')
                        ->options([
                            7 => 'Próximos 7 dias',
                            15 => 'Próximos 15 dias',
                            30 => 'Próximos 30 dias',
                            60 => 'Próximos 60 dias',
                        ])
                        ->placeholder('Sem limite')
                        ->native(false)
                        ->visible(fn (Get $get): bool => $get('status') === 'a_vencer'),
                ]),

            Section::make('Período')
                ->columns(4)
                ->columnSpan(2)
                ->schema([
                    Select::make('data_campo')
                        ->label('Filtrar por data de')
                        ->options([
                            'vencimento' => 'Vencimento',
                            'created_at' => 'Emissão',
                            'data_pagamento' => 'Pagamento',
                        ])
                        ->default('vencimento')
                        ->native(false)
                        ->columnSpan(2),
                    Select::make('modo_periodo')
                        ->label('Granularidade')
                        ->options([
                            'livre' => 'Intervalo livre',
                            'mensal' => 'Mês fixo',
                        ])
                        ->default('livre')
                        ->native(false)
                        ->live()
                        ->columnSpan(2),
                    DatePicker::make('data_de')
                        ->label('De')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->visible(fn (Get $get): bool => ($get('modo_periodo') ?? 'livre') === 'livre'),
                    DatePicker::make('data_ate')
                        ->label('Até')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->visible(fn (Get $get): bool => ($get('modo_periodo') ?? 'livre') === 'livre'),
                    Select::make('mes')
                        ->label('Mês')
                        ->options(self::opcoesMeses())
                        ->default(now()->format('Y-m'))
                        ->native(false)
                        ->visible(fn (Get $get): bool => $get('modo_periodo') === 'mensal')
                        ->columnSpan(2),
                ]),

            Section::make('Categoria')
                ->columns(2)
                ->columnSpan(2)
                ->schema([
                    Select::make('planos_despesa')
                        ->label('Categoria (Despesa)')
                        ->multiple()
                        ->options(fn () => PlanoDespesa::orderBy('nome')->pluck('nome', 'id'))
                        ->searchable()
                        ->native(false)
                        ->visible(fn (Get $get): bool => in_array($get('tipo'), [null, 'pagar'], true)),
                    Select::make('planos_receita')
                        ->label('Categoria (Receita)')
                        ->multiple()
                        ->options(fn () => PlanoReceita::orderBy('nome')->pluck('nome', 'id'))
                        ->searchable()
                        ->native(false)
                        ->visible(fn (Get $get): bool => in_array($get('tipo'), [null, 'receber'], true)),
                ]),

            Section::make('Favorecido e forma de pagamento')
                ->columns(3)
                ->columnSpan(2)
                ->schema([
                    Select::make('favorecido_id')
                        ->label('Fornecedor')
                        ->options(fn (): array => Prestador::fornecedores()
                            ->orderBy('nome')
                            ->get()
                            ->mapWithKeys(fn (Prestador $p): array => [$p->id => $p->nome_exibicao])
                            ->toArray())
                        ->searchable()
                        ->native(false)
                        ->visible(fn (Get $get): bool => in_array($get('tipo'), [null, 'pagar'], true)),
                    Select::make('cliente_id')
                        ->label('Cliente')
                        ->options(fn () => Cliente::orderBy('cliente_nome')->pluck('cliente_nome', 'id'))
                        ->searchable()
                        ->native(false)
                        ->visible(fn (Get $get): bool => in_array($get('tipo'), [null, 'receber'], true)),
                    Select::make('formas_pagamento')
                        ->label('Forma de pagamento')
                        ->multiple()
                        ->options(collect(FormaPagamento::cases())->mapWithKeys(fn (FormaPagamento $f) => [$f->value => $f->getLabel()]))
                        ->native(false),
                ]),
        ])->columns(4);
    }

    /** @return array<string, string> */
    private static function opcoesMeses(): array
    {
        return collect(range(0, 23))
            ->mapWithKeys(function (int $i): array {
                $mes = now()->subMonthsNoOverflow($i);

                return [$mes->format('Y-m') => ucfirst($mes->translatedFormat('F/Y'))];
            })
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('limparFiltros')
                ->label('Limpar filtros')
                ->color('gray')
                ->icon(Heroicon::OutlinedXMark)
                ->action(function (): void {
                    $this->filters = null;
                    $this->getFiltersForm()->fill();
                }),
            Action::make('imprimir')
                ->label('Imprimir')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('primary')
                ->url(fn (): string => route('relatorios.contas_pagar_receber.imprimir', ['filters' => $this->filters ?? []]))
                ->openUrlInNewTab(),
        ];
    }

    public function getWidgets(): array
    {
        return [
            ContasPagarReceberStatsOverview::class,
            ContasPagarReceberAgingWidget::class,
            ContasPagarReceberTopFavorecidosWidget::class,
            ContasPagarReceberDetalhamentoWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
