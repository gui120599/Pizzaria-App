<?php

namespace App\Filament\Resources\Pedidos\Pages;

use App\Filament\Pages\AtenderPedido;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

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

    /**
     * Rascunhos (INICIADO) e excluídos ficam fora da visão padrão. O
     * PedidoResource tira o SoftDeletingScope, então cada aba decide sozinha.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'pedidos' => Tab::make('Pedidos')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutTrashed()->where('pedidos.pedido_status', '!=', 'INICIADO')),
            'rascunhos' => Tab::make('Rascunhos')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutTrashed()->where('pedidos.pedido_status', 'INICIADO')),
            'lixeira' => Tab::make('Lixeira')
                ->icon('heroicon-o-trash')
                ->visible(fn (): bool => Auth::user()?->can('restoreAny', Pedido::class) ?? false)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->onlyTrashed()),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pedidos';
    }
}
