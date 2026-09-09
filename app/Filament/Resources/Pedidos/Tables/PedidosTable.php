<?php

namespace App\Filament\Resources\Pedidos\Tables;

use App\Filament\Pages\AtenderPedido;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PedidosTable
{
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

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('cliente.cliente_nome')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sessaoMesa.mesa.mesa_nome')
                    ->label('Mesa')
                    ->sortable(),
                TextColumn::make('garcom.name')
                    ->label('Atendente')
                    ->sortable(),
                TextColumn::make('entregador.name')
                    ->label('Entregador')
                    ->sortable(),
                TextColumn::make('opcaoEntrega.opcaoentrega_nome')
                    ->label('Entrega')
                    ->sortable(),
                TextColumn::make('pedido_valor_total')
                    ->label('Total')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('pedido_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => self::CORES_STATUS[$state] ?? 'gray'),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('atender')
                    ->label('Atender')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (Pedido $record): string => AtenderPedido::getUrl(['pedido' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
