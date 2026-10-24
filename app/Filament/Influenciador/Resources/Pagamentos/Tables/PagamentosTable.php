<?php

namespace App\Filament\Influenciador\Resources\Pagamentos\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PagamentosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('cupomCredito'))
            ->defaultSort('pgcom_pago_em', 'desc')
            ->emptyStateHeading('Nenhum pagamento recebido ainda')
            ->columns([
                TextColumn::make('pgcom_periodo_referencia')
                    ->label('Período')
                    ->date('m/Y'),

                TextColumn::make('pgcom_valor_total')
                    ->label('Valor')
                    ->money('BRL')
                    ->weight('bold'),

                TextColumn::make('pgcom_forma')
                    ->label('Forma')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label()),

                TextColumn::make('cupomCredito.cupom_codigo')
                    ->label('Cupom de Crédito')
                    ->placeholder('—'),

                TextColumn::make('pgcom_pago_em')
                    ->label('Pago em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ]);
    }
}
