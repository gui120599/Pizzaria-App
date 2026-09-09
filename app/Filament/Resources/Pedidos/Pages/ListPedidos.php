<?php

namespace App\Filament\Resources\Pedidos\Pages;

use App\Filament\Pages\AtenderPedido;
use App\Filament\Resources\Pedidos\PedidoResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPedidos extends ListRecords
{
    protected static string $resource = PedidoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('novo_pedido')
                ->label('Novo pedido')
                ->icon('heroicon-o-plus')
                ->url(AtenderPedido::getUrl()),
        ];
    }
}
