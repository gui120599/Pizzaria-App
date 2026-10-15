<?php

namespace App\Filament\Resources\PontoAjustes\Tables;

use App\Enums\Ponto\OrigemAjuste;
use App\Enums\Ponto\StatusAjuste;
use App\Enums\Ponto\TipoAjuste;
use App\Exceptions\PontoException;
use App\Models\Colaborador;
use App\Models\PontoAjuste;
use App\Services\Ponto\PontoService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class PontoAjustesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['colaborador', 'marcacao', 'solicitadoPor', 'aprovadoPor']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('data_referencia')->label('Dia')->date('d/m/Y')->sortable(),
                TextColumn::make('colaborador.nome')->label('Colaborador')->searchable(),
                TextColumn::make('tipo')->label('Tipo')->badge(),
                TextColumn::make('detalhe')
                    ->label('Horário / minutos')
                    ->state(fn (PontoAjuste $record): ?string => match (true) {
                        $record->data_hora !== null => $record->data_hora->format('d/m H:i'),
                        $record->marcacao !== null => 'Marcação de '.$record->marcacao->registrada_em->format('d/m H:i'),
                        $record->minutos !== null => "{$record->minutos} min",
                        default => null,
                    })
                    ->placeholder('Dia inteiro'),
                TextColumn::make('motivo')->label('Motivo')->badge()->color('gray'),
                TextColumn::make('justificativa')->label('Justificativa')->limit(50)->tooltip(fn (PontoAjuste $record): string => $record->justificativa),
                TextColumn::make('origem')->label('Origem')->badge()->color('gray')->toggleable(),
                TextColumn::make('status')->label('Situação')->badge()
                    ->description(fn (PontoAjuste $record): ?string => $record->aprovadoPor !== null
                        ? "{$record->aprovadoPor->name} em {$record->decidido_em?->format('d/m H:i')}"
                        : null),
            ])
            ->filters([
                SelectFilter::make('status')->label('Situação')->options(StatusAjuste::class)->default(StatusAjuste::Pendente->value),
                SelectFilter::make('tipo')->label('Tipo')->options(TipoAjuste::class),
                SelectFilter::make('origem')->label('Origem')->options(OrigemAjuste::class),
                SelectFilter::make('colaborador_id')->label('Colaborador')
                    ->options(fn (): array => Colaborador::query()->orderBy('nome')->pluck('nome', 'id')->all())
                    ->searchable(),
            ])
            ->recordActions([
                Action::make('aprovar')
                    ->label('Aprovar')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->authorize('aprovar')
                    ->requiresConfirmation()
                    ->modalDescription('O dia é reapurado e o colaborador é avisado.')
                    ->action(fn (PontoAjuste $record) => self::decidir(collect([$record]), null)),
                Action::make('rejeitar')
                    ->label('Rejeitar')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->authorize('aprovar')
                    ->schema([Textarea::make('motivo_rejeicao')->label('Motivo')->required()->rows(2)])
                    ->action(fn (PontoAjuste $record, array $data) => self::decidir(collect([$record]), $data['motivo_rejeicao'])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('aprovarSelecionados')
                        ->label('Aprovar selecionados')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => self::decidir($records, null))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('rejeitarSelecionados')
                        ->label('Rejeitar selecionados')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('danger')
                        ->schema([Textarea::make('motivo_rejeicao')->label('Motivo')->required()->rows(2)])
                        ->action(fn (Collection $records, array $data) => self::decidir($records, $data['motivo_rejeicao']))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->checkIfRecordIsSelectableUsing(fn (PontoAjuste $record): bool => $record->status === StatusAjuste::Pendente);
    }

    /**
     * Aprova (motivo nulo) ou rejeita um a um; o que o service recusar (próprio
     * ponto, período fechado) é listado sem impedir os demais.
     *
     * @param  \Illuminate\Support\Collection<int, PontoAjuste>  $ajustes
     */
    private static function decidir(\Illuminate\Support\Collection $ajustes, ?string $motivoRejeicao): void
    {
        $ponto = app(PontoService::class);
        $recusados = [];
        $feitos = 0;

        foreach ($ajustes as $ajuste) {
            try {
                $motivoRejeicao === null
                    ? $ponto->aprovarAjuste($ajuste, auth()->user())
                    : $ponto->rejeitarAjuste($ajuste, auth()->user(), $motivoRejeicao);
                $feitos++;
            } catch (PontoException $e) {
                $recusados[] = "{$ajuste->colaborador->nome} ({$ajuste->data_referencia->format('d/m')}): {$e->getMessage()}";
            }
        }

        $titulo = ($motivoRejeicao === null ? 'Aprovados: ' : 'Rejeitados: ').$feitos;

        $recusados === []
            ? Notification::make()->title($titulo)->success()->send()
            : Notification::make()->title($titulo)->body(implode("\n", $recusados))->warning()->persistent()->send();
    }
}
