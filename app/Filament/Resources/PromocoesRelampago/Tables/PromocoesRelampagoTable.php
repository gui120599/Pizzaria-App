<?php

namespace App\Filament\Resources\PromocoesRelampago\Tables;

use App\Models\PromocaoRelampago;
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

class PromocoesRelampagoTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('promocaoProdutos'))
            ->columns([
                TextColumn::make('promocao_nome')
                    ->label('Promoção')
                    ->searchable()
                    ->sortable(),

                // Derivado de promocao_ativa + vigência + saldo; não há coluna no banco.
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (PromocaoRelampago $record) => $record->status())
                    ->badge(),

                TextColumn::make('vigencia')
                    ->label('Vigência')
                    ->state(function (PromocaoRelampago $record): string {
                        if (! $record->promocao_recorrente) {
                            return $record->promocao_inicio->format('d/m/Y H:i').' até '.$record->promocao_fim?->format('d/m/Y H:i');
                        }

                        $dias = $record->promocao_dias_semana;
                        $nomesDias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
                        $diasLabel = empty($dias)
                            ? 'todo dia'
                            : collect($dias)->sort()->map(fn ($d) => $nomesDias[(int) $d])->implode('/');

                        $horario = $record->promocao_hora_inicio && $record->promocao_hora_fim
                            ? $record->promocao_hora_inicio->format('H:i').'-'.$record->promocao_hora_fim->format('H:i')
                            : 'dia inteiro';

                        return $diasLabel.', '.$horario;
                    })
                    ->wrap(),

                TextColumn::make('saldo')
                    ->label('Restam')
                    ->state(function (PromocaoRelampago $record): string {
                        if ($record->promocao_qtd_total === null) {
                            return 'Ilimitado';
                        }

                        return number_format($record->saldoDisponivel(), 0, ',', '.')
                            .' de '.number_format($record->promocao_qtd_total, 0, ',', '.');
                    })
                    ->badge()
                    ->color(fn (PromocaoRelampago $record) => match (true) {
                        $record->promocao_qtd_total === null => 'gray',
                        $record->esgotada() => 'danger',
                        $record->saldoDisponivel() <= ($record->promocao_qtd_total * 0.2) => 'warning',
                        default => 'success',
                    }),

                TextColumn::make('promocao_produtos_count')
                    ->label('Produtos')
                    ->badge()
                    ->color('gray'),
            ])
            ->filters([
                Filter::make('vigentes')
                    ->label('Somente vigentes')
                    ->query(fn (Builder $query) => $query->vigente()),
                TrashedFilter::make(),
            ])
            ->defaultSort('promocao_inicio', 'desc')
            ->recordActions([
                Action::make('encerrar')
                    ->label('Encerrar agora')
                    ->icon(Heroicon::OutlinedStopCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('A promoção some do cardápio imediatamente. Os pedidos já feitos mantêm o preço promocional.')
                    // Recorrente: mostra sempre que ativa, mesmo fora da janela do
                    // dia (o botão encerra a recorrência inteira, não só a
                    // ocorrência de hoje). Não recorrente: só enquanto vigente.
                    ->visible(fn (PromocaoRelampago $record) => $record->promocao_ativa
                        && ($record->promocao_recorrente || $record->vigente()))
                    ->action(function (PromocaoRelampago $record) {
                        $record->update(['promocao_ativa' => false]);

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
