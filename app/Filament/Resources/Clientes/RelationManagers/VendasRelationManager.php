<?php

namespace App\Filament\Resources\Clientes\RelationManagers;

use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VendasRelationManager extends RelationManager
{
    protected static string $relationship = 'vendas';

    protected static ?string $title = 'Vendas do cliente';

    protected static ?string $modelLabel = 'venda';

    protected static ?string $pluralModelLabel = 'vendas';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            // Eager load das formas de pagamento para evitar N+1 na coluna abaixo.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('pagamentos.opcaoPagamento'))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Nenhuma venda para este cliente')
            ->emptyStateIcon('heroicon-o-shopping-bag')
            ->columns([
                TextColumn::make('id')
                    ->label('Venda')
                    ->prefix('#')
                    ->sortable(),

                TextColumn::make('venda_datahora_finalizada')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('venda_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'FINALIZADA' => 'success',
                        'CANCELADA' => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('venda_valor_total')
                    ->label('Total')
                    ->money('BRL')
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('venda_valor_pago')
                    ->label('Recebido')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('formas_pagamento')
                    ->label('Forma(s) de pagamento')
                    ->state(fn ($record): string => $record->pagamentos
                        ->map(fn ($p) => $p->opcaoPagamento?->opcaopag_nome)
                        ->filter()
                        ->unique()
                        ->implode(', ') ?: '—'),
            ])
            ->filters([
                SelectFilter::make('venda_status')
                    ->label('Status')
                    ->options([
                        'INICIADA' => 'Iniciada',
                        'FINALIZADA' => 'Finalizada',
                        'CANCELADA' => 'Cancelada',
                    ]),

                Filter::make('periodo')
                    ->schema([
                        DatePicker::make('inicio')->label('De'),
                        DatePicker::make('fim')->label('Até'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['inicio'] ?? null, fn (Builder $q, $d) => $q->whereDate('venda_datahora_finalizada', '>=', $d))
                        ->when($data['fim'] ?? null, fn (Builder $q, $d) => $q->whereDate('venda_datahora_finalizada', '<=', $d))),
            ]);
    }
}
