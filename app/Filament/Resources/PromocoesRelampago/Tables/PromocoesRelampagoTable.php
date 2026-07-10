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

                TextColumn::make('promocao_inicio')
                    ->label('Início')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('promocao_fim')
                    ->label('Fim')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

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
                    ->visible(fn (PromocaoRelampago $record) => $record->vigente())
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
