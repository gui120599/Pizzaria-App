<?php

namespace App\Filament\Resources\Vendas\Tables;

use App\Filament\Pages\OperarVenda;
use App\Models\Venda;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
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
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
