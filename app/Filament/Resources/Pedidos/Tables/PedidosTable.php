<?php

namespace App\Filament\Resources\Pedidos\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PedidosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('pedido_cliente_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('sessaoMesa.mesa.mesa_nome')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('garcom.name')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('entregador.name')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('opcaoEntrega.opcaoentrega_nome')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('pedido_venda_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('pedido_descricao_pagamento')
                    ->searchable(),
                TextColumn::make('pedido_valor_itens')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('pedido_valor_desconto')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('pedido_valor_total')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('pedido_status')
                    ->badge(),
                TextColumn::make('pedido_datahora_incio')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('pedido_datahora_abertura')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('pedido_datahora_preparo')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('pedido_datahora_pronto')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('pedido_datahora_transporte')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('pedido_datahora_entrega')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('pedido_datahora_finalizado')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('pedido_datahora_cancelado')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
