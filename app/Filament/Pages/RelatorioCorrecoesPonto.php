<?php

namespace App\Filament\Pages;

use App\Enums\Ponto\OrigemAjuste;
use App\Enums\Ponto\StatusAjuste;
use App\Enums\Ponto\TipoAjuste;
use App\Models\Colaborador;
use App\Models\PontoAjuste;
use App\Models\PontoPeriodo;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Correções de ponto feitas por gestores (spec §5.2.1/§6), por período,
 * gestor e colaborador. Só Admin (view:relatorio_correcoes_ponto).
 */
class RelatorioCorrecoesPonto extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $routePath = '/relatorio-correcoes-ponto';

    protected static ?string $title = 'Relatório de correções';

    protected static ?string $navigationLabel = 'Relatório de correções';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static UnitEnum|string|null $navigationGroup = 'Ponto e Repasses';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.relatorio-correcoes-ponto';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('view:relatorio_correcoes_ponto') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(PontoAjuste::query()->where('origem', OrigemAjuste::Gestor)->with(['colaborador', 'aprovadoPor', 'desfeitoPor', 'marcacao']))
            ->defaultSort('decidido_em', 'desc')
            ->groups([
                Group::make('aprovado_por')->label('Gestor')->getTitleFromRecordUsing(fn (PontoAjuste $record): string => $record->aprovadoPor?->name ?? '—'),
                Group::make('colaborador_id')->label('Colaborador')->getTitleFromRecordUsing(fn (PontoAjuste $record): string => $record->colaborador->nome),
            ])
            ->columns([
                TextColumn::make('data_referencia')->label('Dia')->date('d/m/Y')->sortable(),
                TextColumn::make('colaborador.nome')->label('Colaborador')->searchable(),
                TextColumn::make('tipo')->label('Correção')->badge(),
                TextColumn::make('detalhe')->label('Horário')
                    ->state(fn (PontoAjuste $record): ?string => $record->data_hora?->format('H:i') ?? $record->marcacao?->registrada_em->format('H:i') ?? ($record->minutos !== null ? "{$record->minutos} min" : null))
                    ->placeholder('Dia inteiro'),
                TextColumn::make('motivo')->label('Motivo')->badge()->color('gray'),
                TextColumn::make('justificativa')->label('Justificativa')->wrap()->limit(80),
                TextColumn::make('aprovadoPor.name')->label('Gestor'),
                TextColumn::make('decidido_em')->label('Quando')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('status')->label('Situação')->badge()
                    ->description(fn (PontoAjuste $record): ?string => $record->status === StatusAjuste::Desfeito ? "por {$record->desfeitoPor?->name}: {$record->justificativa_desfazer}" : null),
            ])
            ->filters([
                SelectFilter::make('periodo')
                    ->label('Período')
                    ->options(fn (): array => PontoPeriodo::query()->orderByDesc('data_inicio')->limit(40)->get()
                        ->mapWithKeys(fn (PontoPeriodo $periodo): array => [$periodo->id => "{$periodo->periodicidade->getLabel()} {$periodo->rotulo()}"])
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $periodo = filled($data['value'] ?? null) ? PontoPeriodo::query()->find($data['value']) : null;

                        return $periodo === null ? $query : $query
                            ->whereDate('data_referencia', '>=', $periodo->data_inicio->toDateString())
                            ->whereDate('data_referencia', '<=', $periodo->data_fim->toDateString())
                            ->whereHas('colaborador', fn (Builder $q) => $q->where('periodicidade_repasse', $periodo->periodicidade));
                    }),
                SelectFilter::make('aprovado_por')->label('Gestor')
                    ->options(fn (): array => User::query()->whereIn('id', PontoAjuste::query()->where('origem', OrigemAjuste::Gestor)->select('aprovado_por'))->pluck('name', 'id')->all()),
                SelectFilter::make('colaborador_id')->label('Colaborador')
                    ->options(fn (): array => Colaborador::query()->orderBy('nome')->pluck('nome', 'id')->all())->searchable(),
                SelectFilter::make('tipo')->label('Correção')->options(TipoAjuste::class),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportarCsv')
                ->label('CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportarCsv()),
        ];
    }

    /** Exporta o que os filtros da tabela mostram. */
    private function exportarCsv(): StreamedResponse
    {
        $ajustes = $this->getFilteredSortedTableQuery()->get();

        return response()->streamDownload(function () use ($ajustes): void {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, ['Dia', 'Colaborador', 'Correção', 'Horário', 'Motivo', 'Justificativa', 'Gestor', 'Quando', 'Situação'], ';');

            foreach ($ajustes as $ajuste) {
                fputcsv($saida, [
                    $ajuste->data_referencia->format('d/m/Y'),
                    $ajuste->colaborador->nome,
                    $ajuste->tipo->getLabel(),
                    $ajuste->data_hora?->format('H:i') ?? $ajuste->marcacao?->registrada_em->format('H:i') ?? ($ajuste->minutos !== null ? "{$ajuste->minutos} min" : 'Dia inteiro'),
                    $ajuste->motivo->getLabel(),
                    $ajuste->justificativa,
                    $ajuste->aprovadoPor?->name,
                    $ajuste->decidido_em?->format('d/m/Y H:i'),
                    $ajuste->status->getLabel(),
                ], ';');
            }

            fclose($saida);
        }, 'correcoes-ponto.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
