<?php

namespace App\Filament\Pages;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Widgets\CozinhaAgoraWidget;
use App\Filament\Widgets\ProducaoRankingsWidget;
use App\Filament\Widgets\ProducaoStatsOverview;
use App\Filament\Widgets\TempoMedioProducaoWidget;
use App\Models\ProducaoItem;
use App\Models\Produto;
use App\Models\SetorProducao;
use App\Models\User;
use App\Services\Cozinha\RelatorioProducaoService;
use App\Support\Cozinha\ModuloCozinha;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Relatório de produção da cozinha (spec §7.1): tempos de fila/preparo/total,
 * % no prazo, ritmo, produtos mais lentos, operadores, refações e cobertura
 * de fichas. Mesmo padrão dos outros relatórios (Dashboard + HasFiltersForm +
 * widgets lendo $pageFilters); a regra fica no RelatorioProducaoService.
 */
class RelatorioProducao extends BaseDashboard
{
    use HasFiltersForm;

    protected static string $routePath = '/relatorio-producao';

    protected static ?string $title = 'Relatório de Produção';

    protected static ?string $navigationLabel = 'Relatório de Produção';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static UnitEnum|string|null $navigationGroup = 'Cozinha';

    protected static ?int $navigationSort = 40;

    public static function canAccess(): bool
    {
        return ModuloCozinha::ativo() && Gate::allows('verRelatorio', ProducaoItem::class);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Filtros')
                ->description('Itens concluídos pela cozinha no período.')
                ->icon('heroicon-o-funnel')
                ->columns(5)
                ->columnSpanFull()
                ->schema([
                    DatePicker::make('inicio')
                        ->label('Início')
                        ->default(now()->subDays(6))
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->maxDate(now()),
                    DatePicker::make('fim')
                        ->label('Fim')
                        ->default(now())
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->maxDate(now()),
                    Select::make('setor_id')
                        ->label('Setor')
                        ->options(fn (): array => SetorProducao::withTrashed()->ordenados()->pluck('nome', 'id')->all())
                        ->placeholder('Todos')
                        ->native(false),
                    Select::make('produto_id')
                        ->label('Produto')
                        ->options(fn (): array => Produto::where('produto_tipo', ProdutoTipoEnum::PRODUZIDO->value)->orderBy('produto_descricao')->pluck('produto_descricao', 'id')->all())
                        ->placeholder('Todos')
                        ->searchable(),
                    Select::make('operador_id')
                        ->label('Operador')
                        ->options(fn (): array => User::query()
                            ->whereIn('id', ProducaoItem::query()->whereNotNull('operador_id')->distinct()->select('operador_id'))
                            ->orderBy('name_first')
                            ->pluck('name_first', 'id')
                            ->all())
                        ->placeholder('Todos')
                        ->searchable(),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportarCsv')
                ->label('Exportar CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportarCsv()),
        ];
    }

    public function getWidgets(): array
    {
        return [
            CozinhaAgoraWidget::class,
            TempoMedioProducaoWidget::class,
            ProducaoStatsOverview::class,
            ProducaoRankingsWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    /** Um item concluído por linha, com os tempos em minutos (abre direto no Excel pt-BR). */
    public function exportarCsv(): StreamedResponse
    {
        $filtros = $this->filters ?? [];
        $inicio = Carbon::parse($filtros['inicio'] ?? now()->subDays(6))->startOfDay();
        $fim = Carbon::parse($filtros['fim'] ?? now())->endOfDay();
        $linhas = app(RelatorioProducaoService::class)->linhasDetalhe($inicio, $fim, [
            'setor_id' => filled($filtros['setor_id'] ?? null) ? (int) $filtros['setor_id'] : null,
            'produto_id' => filled($filtros['produto_id'] ?? null) ? (int) $filtros['produto_id'] : null,
            'operador_id' => filled($filtros['operador_id'] ?? null) ? (int) $filtros['operador_id'] : null,
        ]);

        return response()->streamDownload(function () use ($linhas): void {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, ['Item', 'Pedido', 'Referência', 'Setor', 'Produto', 'Qtd.', 'Operador', 'Liberado', 'Iniciado', 'Concluído', 'Fila (min)', 'Preparo (min)', 'Total (min)', 'Alvo (min)', 'No prazo', 'Refação'], ';');

            $minutos = fn (?string $de, ?string $ate): string => $de && $ate
                ? number_format(Carbon::parse($de)->diffInSeconds(Carbon::parse($ate)) / 60, 1, ',', '')
                : '';

            foreach ($linhas as $l) {
                $alvo = (int) ($l->tempo_previsto_min ?? $l->tempo_alvo_min);
                $total = Carbon::parse($l->liberado_em)->diffInSeconds(Carbon::parse($l->concluido_em));

                fputcsv($saida, [
                    $l->id,
                    $l->pedido_id,
                    $l->referencia,
                    $l->setor,
                    $l->produto,
                    rtrim(rtrim(number_format((float) $l->quantidade, 3, ',', ''), '0'), ','),
                    $l->operador_nome,
                    Carbon::parse($l->liberado_em)->format('d/m/Y H:i:s'),
                    $l->iniciado_em ? Carbon::parse($l->iniciado_em)->format('d/m/Y H:i:s') : '',
                    Carbon::parse($l->concluido_em)->format('d/m/Y H:i:s'),
                    $minutos($l->liberado_em, $l->iniciado_em),
                    $minutos($l->iniciado_em, $l->concluido_em),
                    $minutos($l->liberado_em, $l->concluido_em),
                    $alvo,
                    $total <= $alvo * 60 ? 'Sim' : 'Não',
                    $l->geracao > 1 ? 'Sim' : 'Não',
                ], ';');
            }

            fclose($saida);
        }, 'producao-'.$inicio->format('Ymd').'-'.$fim->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
