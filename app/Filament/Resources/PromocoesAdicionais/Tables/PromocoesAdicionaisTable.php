<?php

namespace App\Filament\Resources\PromocoesAdicionais\Tables;

use App\Models\PromocaoAdicional;
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

class PromocoesAdicionaisTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('regras'))
            ->columns([
                TextColumn::make('promoad_nome')
                    ->label('Promoção')
                    ->searchable()
                    ->sortable(),

                // Derivado de promoad_ativa + vigência; não há coluna no banco.
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (PromocaoAdicional $record) => $record->status())
                    ->badge(),

                TextColumn::make('vigencia')
                    ->label('Vigência')
                    ->state(function (PromocaoAdicional $record): string {
                        if (! $record->promoad_recorrente) {
                            return $record->promoad_inicio->format('d/m/Y H:i').' até '.$record->promoad_fim?->format('d/m/Y H:i');
                        }

                        $dias = $record->promoad_dias_semana;
                        $nomesDias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
                        $diasLabel = empty($dias)
                            ? 'todo dia'
                            : collect($dias)->sort()->map(fn ($d) => $nomesDias[(int) $d])->implode('/');

                        $horario = $record->promoad_hora_inicio && $record->promoad_hora_fim
                            ? $record->promoad_hora_inicio->format('H:i').'-'.$record->promoad_hora_fim->format('H:i')
                            : 'dia inteiro';

                        return $diasLabel.', '.$horario;
                    })
                    ->wrap(),

                TextColumn::make('regras_count')
                    ->label('Regras')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('promoad_aplica_fracionado')
                    ->label('Fracionado')
                    ->state(fn (PromocaoAdicional $record) => $record->promoad_aplica_fracionado ? 'Sim' : 'Não')
                    ->badge()
                    ->color(fn (PromocaoAdicional $record) => $record->promoad_aplica_fracionado ? 'success' : 'gray'),
            ])
            ->filters([
                Filter::make('vigentes')
                    ->label('Somente vigentes')
                    ->query(fn (Builder $query) => $query->vigente()),
                TrashedFilter::make(),
            ])
            ->defaultSort('promoad_inicio', 'desc')
            ->recordActions([
                Action::make('encerrar')
                    ->label('Encerrar agora')
                    ->icon(Heroicon::OutlinedStopCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('A promoção some do cardápio imediatamente. Os pedidos já feitos mantêm o benefício aceito.')
                    ->visible(fn (PromocaoAdicional $record) => $record->promoad_ativa
                        && ($record->promoad_recorrente || $record->vigente()))
                    ->action(function (PromocaoAdicional $record) {
                        $record->update(['promoad_ativa' => false]);

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
