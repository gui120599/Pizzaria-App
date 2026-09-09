<?php

namespace App\Filament\Resources\Pedidos;

use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Filament\Resources\Pedidos\Tables\PedidosTable;
use App\Models\Pedido;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Índice histórico de pedidos — criar/editar de verdade acontece na
 * App\Filament\Pages\AtenderPedido (Page Livewire dedicada, não Resource
 * form), ligada pelos botões de PedidosTable/ListPedidos.
 */
class PedidoResource extends Resource
{
    protected static ?string $model = Pedido::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Pedidos';

    protected static UnitEnum|string|null $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'id';

    public static function table(Table $table): Table
    {
        return PedidosTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPedidos::route('/'),
        ];
    }
}
