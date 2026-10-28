<?php

namespace App\Filament\Influenciador\Resources\Comissoes\Tables;

use App\Enums\StatusComissaoEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ComissoesTable
{
    /** Só dados da própria comissão/pedido — nenhum dado de cliente. */
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('cupom'))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nenhuma comissão ainda')
            ->columns([
                TextColumn::make('comissao_pedido_id')
                    ->label('Pedido')
                    ->prefix('#'),

                TextColumn::make('cupom.cupom_codigo')
                    ->label('Cupom'),

                TextColumn::make('comissao_base_calculo')
                    ->label('Base de Cálculo')
                    ->money('BRL'),

                TextColumn::make('comissao_valor')
                    ->label('Comissão')
                    ->money('BRL')
                    ->weight('bold'),

                TextColumn::make('comissao_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->color(fn ($state) => match ($state) {
                        StatusComissaoEnum::PENDENTE => 'gray',
                        StatusComissaoEnum::CREDITADA => 'success',
                        StatusComissaoEnum::ESTORNADA => 'warning',
                        StatusComissaoEnum::CANCELADA => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ]);
    }
}
