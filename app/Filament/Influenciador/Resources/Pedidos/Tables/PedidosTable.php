<?php

namespace App\Filament\Influenciador\Resources\Pedidos\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PedidosTable
{
    /**
     * Só colunas do próprio pedido/cupom — nenhum dado de cliente (nome,
     * telefone, endereço) é exposto aqui de propósito.
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('cupom'))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nenhum pedido com seu cupom ainda')
            ->columns([
                TextColumn::make('id')
                    ->label('Pedido #'),

                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('cupom.cupom_codigo')
                    ->label('Cupom'),

                TextColumn::make('pedido_valor_total')
                    ->label('Valor do Pedido')
                    ->money('BRL'),

                TextColumn::make('pedido_valor_desconto_cupom')
                    ->label('Desconto do Cupom')
                    ->money('BRL'),

                TextColumn::make('pedido_status')
                    ->label('Status')
                    ->badge(),
            ]);
    }
}
