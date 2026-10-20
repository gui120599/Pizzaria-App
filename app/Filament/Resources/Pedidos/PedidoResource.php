<?php

namespace App\Filament\Resources\Pedidos;

use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Filament\Resources\Pedidos\Pages\ViewPedido;
use App\Filament\Resources\Pedidos\Schemas\PedidoInfolist;
use App\Filament\Resources\Pedidos\Tables\PedidosTable;
use App\Models\Pedido;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Índice histórico de pedidos, com página de visualização somente leitura —
 * criar/editar de verdade acontece na
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

    /**
     * Sem o SoftDeletingScope: quem decide se o pedido excluído aparece são
     * as abas da lista (Lixeira) e a página de visualização.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PedidoInfolist::configure($schema);
    }

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
            'view' => ViewPedido::route('/{record}'),
        ];
    }
}
