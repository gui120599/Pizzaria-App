<?php

namespace App\Filament\Resources\Clientes\RelationManagers;

use App\Enums\PedidoOrigemEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PedidosRelationManager extends RelationManager
{
    protected static string $relationship = 'pedidos';

    protected static ?string $title = 'Pedidos do cliente';

    protected static ?string $modelLabel = 'pedido';

    protected static ?string $pluralModelLabel = 'pedidos';

    private const CORES_STATUS = [
        'INICIADO' => 'gray',
        'ABERTO' => 'info',
        'PREPARANDO' => 'warning',
        'PRONTO' => 'warning',
        'EM TRANSPORTE' => 'info',
        'ENTREGUE' => 'success',
        'FINALIZADO' => 'success',
        'CANCELADO' => 'danger',
    ];

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Nenhum pedido para este cliente')
            ->emptyStateIcon('heroicon-o-shopping-bag')
            ->columns([
                TextColumn::make('id')
                    ->label('Pedido')
                    ->prefix('#')
                    ->sortable(),

                TextColumn::make('pedido_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => self::CORES_STATUS[$state] ?? 'gray'),

                TextColumn::make('pedido_origem')
                    ->label('Origem')
                    ->badge()
                    ->formatStateUsing(fn (?PedidoOrigemEnum $state): string => $state?->label() ?? '—')
                    ->color('gray'),

                TextColumn::make('pedido_datahora_abertura')
                    ->label('Aberto em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('pedido_valor_total')
                    ->label('Total')
                    ->money('BRL')
                    ->weight('bold')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('pedido_status')
                    ->label('Status')
                    ->options([
                        'INICIADO' => 'Iniciado',
                        'ABERTO' => 'Aberto',
                        'PREPARANDO' => 'Preparando',
                        'PRONTO' => 'Pronto',
                        'EM TRANSPORTE' => 'Em transporte',
                        'ENTREGUE' => 'Entregue',
                        'FINALIZADO' => 'Finalizado',
                        'CANCELADO' => 'Cancelado',
                    ]),

                Filter::make('periodo')
                    ->schema([
                        DatePicker::make('inicio')->label('De'),
                        DatePicker::make('fim')->label('Até'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['inicio'] ?? null, fn (Builder $q, $d) => $q->whereDate('pedido_datahora_abertura', '>=', $d))
                        ->when($data['fim'] ?? null, fn (Builder $q, $d) => $q->whereDate('pedido_datahora_abertura', '<=', $d))),
            ]);
    }
}
