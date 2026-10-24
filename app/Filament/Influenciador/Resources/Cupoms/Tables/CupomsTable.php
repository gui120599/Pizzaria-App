<?php

namespace App\Filament\Influenciador\Resources\Cupoms\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CupomsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('cupom_codigo')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('pedidos'))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nenhum cupom cadastrado')
            ->emptyStateDescription('Crie um cupom para seus seguidores usarem no cardápio.')
            ->columns([
                TextColumn::make('cupom_codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cupom_tipo_desconto')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->badge(),

                TextColumn::make('cupom_valor_desconto')
                    ->label('Desconto')
                    ->money('BRL'),

                TextColumn::make('cupom_valor_minimo_pedido')
                    ->label('Pedido Mínimo')
                    ->money('BRL')
                    ->placeholder('Sem mínimo'),

                TextColumn::make('pedidos_count')
                    ->label('Usos')
                    ->badge()
                    ->sortable(),

                TextColumn::make('cupom_limite_uso_total')
                    ->label('Limite Total')
                    ->placeholder('Ilimitado'),

                TextColumn::make('cupom_data_expiracao')
                    ->label('Expira em')
                    ->date('d/m/Y')
                    ->placeholder('Sem expiração')
                    ->sortable(),

                IconColumn::make('cupom_ativo')
                    ->label('Ativo')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Cadastro')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
