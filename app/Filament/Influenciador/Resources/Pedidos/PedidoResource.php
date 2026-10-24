<?php

namespace App\Filament\Influenciador\Resources\Pedidos;

use App\Filament\Influenciador\Resources\Pedidos\Pages\ListPedidos;
use App\Filament\Influenciador\Resources\Pedidos\Tables\PedidosTable;
use App\Models\Pedido;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class PedidoResource extends Resource
{
    protected static ?string $model = Pedido::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationLabel = 'Pedidos com meu Cupom';

    protected static ?string $modelLabel = 'Pedido';

    /**
     * Somente leitura de propósito: o influenciador só acompanha o que o
     * cupom dele gerou, não gerencia o pedido em si. Nenhuma coluna de
     * cliente (nome/telefone/endereço) é exposta (ver PedidosTable).
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('cupom', fn (Builder $query) => $query->where('cupom_influenciador_id', Auth::guard('influenciador')->id()));
    }

    public static function table(Table $table): Table
    {
        return PedidosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPedidos::route('/'),
        ];
    }
}
