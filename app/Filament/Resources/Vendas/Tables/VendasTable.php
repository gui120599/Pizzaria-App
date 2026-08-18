<?php

namespace App\Filament\Resources\Vendas\Tables;

use App\Exceptions\NfeIoException;
use App\Filament\Pages\OperarVenda;
use App\Models\Venda;
use App\Services\NfeIoService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class VendasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('sessaoCaixa.caixa.caixa_nome')
                    ->label('Caixa')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('cliente.cliente_nome')
                    ->label('Cliente')
                    ->sortable()
                    ->searchable()
                    ->default('—'),

                TextColumn::make('venda_valor_itens')
                    ->label('Itens')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('venda_valor_desconto')
                    ->label('Desconto')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('venda_valor_total')
                    ->label('Total')
                    ->money('BRL')
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('venda_valor_pago')
                    ->label('Pago')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('venda_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'FINALIZADA' => 'success',
                        'CANCELADA' => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('venda_datahora_iniciada')
                    ->label('Iniciada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('venda_datahora_finalizada')
                    ->label('Finalizada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('venda_status_nfe')
                    ->label('NFC-e')
                    ->badge()
                    ->default('—')
                    ->color(fn (?string $state): string => match ($state) {
                        'Issued' => 'success',
                        'Error', 'Failed' => 'danger',
                        'Cancelled' => 'gray',
                        'CancelamentoSolicitado' => 'warning',
                        default => 'info',
                    }),

                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('venda_status')
                    ->label('Status')
                    ->options([
                        'INICIADA' => 'Iniciada',
                        'FINALIZADA' => 'Finalizada',
                        'CANCELADA' => 'Cancelada',
                    ]),
            ])
            ->recordActions([
                Action::make('operar')
                    ->label('Operar')
                    ->icon('heroicon-o-shopping-cart')
                    ->url(fn (Venda $record) => OperarVenda::getUrl(['venda' => $record]))
                    ->visible(fn (Venda $record) => $record->venda_status === 'INICIADA'),

                Action::make('imprimirDanfe')
                    ->label('DANFE')
                    ->icon('heroicon-o-printer')
                    ->url(fn (Venda $record) => route('venda.imprimir_NFE', ['id_nfe' => $record->venda_id_nfe]))
                    ->openUrlInNewTab()
                    ->visible(fn (Venda $record) => filled($record->venda_id_nfe)),

                Action::make('cancelarNfe')
                    ->label('Cancelar NFC-e')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('O cancelamento é enviado à NFe.io e processado de forma assíncrona — o status só reflete a confirmação real quando o webhook chegar.')
                    ->action(function (Venda $record, NfeIoService $service): void {
                        try {
                            $service->cancelar($record->venda_id_nfe);
                            $record->update(['venda_status_nfe' => 'CancelamentoSolicitado']);

                            Notification::make()
                                ->title('Cancelamento solicitado')
                                ->success()
                                ->send();
                        } catch (NfeIoException $e) {
                            Notification::make()
                                ->title('Não foi possível cancelar a NFC-e')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(fn (Venda $record) => $record->venda_status_nfe === 'Issued'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
