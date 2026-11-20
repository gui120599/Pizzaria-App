<?php

namespace App\Filament\Resources\PromocoesCombo\Tables;

use App\Models\PromocaoCombo;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PromocoesComboTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('grupos')->withCount(['participantes', 'aplicacoes']))
            ->columns([
                TextColumn::make('promocombo_nome')
                    ->label('Promoção')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (PromocaoCombo $record) => $record->status())
                    ->badge(),

                TextColumn::make('regra')
                    ->label('Regra')
                    ->state(fn (PromocaoCombo $record): string => $record->composicao().' por R$ '.number_format((float) $record->promocombo_preco, 2, ',', '.'))
                    ->wrap(),

                TextColumn::make('vigencia')
                    ->label('Vigência')
                    ->state(function (PromocaoCombo $record): string {
                        if (! $record->promocombo_recorrente) {
                            return $record->promocombo_inicio?->format('d/m/Y H:i').' até '.$record->promocombo_fim?->format('d/m/Y H:i');
                        }

                        $nomesDias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
                        $dias = $record->promocombo_dias_semana;
                        $diasLabel = empty($dias)
                            ? 'todo dia'
                            : collect($dias)->sort()->map(fn ($d) => $nomesDias[(int) $d])->implode('/');

                        $horario = $record->promocombo_hora_inicio && $record->promocombo_hora_fim
                            ? $record->promocombo_hora_inicio->format('H:i').'-'.$record->promocombo_hora_fim->format('H:i')
                            : 'dia inteiro';

                        return $diasLabel.', '.$horario;
                    })
                    ->wrap(),

                TextColumn::make('participantes_count')
                    ->label('Participantes')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('aplicacoes_count')
                    ->label('Combos aplicados')
                    ->badge()
                    ->color('success'),

                TextColumn::make('restam')
                    ->label('Restam')
                    ->state(fn (PromocaoCombo $record): ?string => $record->promocombo_qtd_total === null
                        ? null
                        : $record->combosRestantes().' de '.$record->promocombo_qtd_total)
                    ->placeholder('Sem limite')
                    ->badge()
                    ->color(fn (PromocaoCombo $record): string => $record->combosRestantes() === 0 ? 'danger' : 'gray'),
            ])
            ->filters([
                Filter::make('vigentes')
                    ->label('Somente vigentes')
                    ->query(fn (Builder $query) => $query->vigente()),
                TrashedFilter::make(),
            ])
            ->defaultSort('promocombo_inicio', 'desc')
            ->recordActions([
                Action::make('encerrar')
                    ->label('Encerrar agora')
                    ->icon(Heroicon::OutlinedStopCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('A promoção deixa de valer para pedidos novos. Os pedidos que já têm o combo o mantêm.')
                    ->visible(fn (PromocaoCombo $record) => $record->promocombo_ativa
                        && ($record->promocombo_recorrente || $record->vigente()))
                    ->action(function (PromocaoCombo $record) {
                        $record->update(['promocombo_ativa' => false]);

                        Notification::make()
                            ->title('Promoção encerrada')
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }
}
