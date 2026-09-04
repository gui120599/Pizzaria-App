<?php

namespace App\Filament\Resources\StonePedidos;

use App\Filament\Resources\StonePedidos\Pages\ManageStonePedidos;
use App\Filament\Resources\StonePedidos\Tables\StonePedidosTable;
use App\Models\StonePedido;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Acompanhamento dos pedidos criados na maquininha Stone a partir do PDV —
 * status, valor, NSU e se o pedido já foi fechado na Stone. As ações "Fechar"
 * e "Cancelar" destravam o limite de 30 pedidos abertos sem precisar de SSH.
 */
class StonePedidoResource extends Resource
{
    protected static ?string $model = StonePedido::class;

    protected static ?string $slug = 'stone-pedidos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Pedidos Stone';

    protected static ?string $modelLabel = 'pedido Stone';

    protected static ?string $pluralModelLabel = 'pedidos Stone';

    protected static ?int $navigationSort = 15;

    protected static ?string $recordTitleAttribute = 'stp_order_code';

    public static function shouldRegisterNavigation(): bool
    {
        return filled(config('services.stone.secret_key'));
    }

    public static function table(Table $table): Table
    {
        return StonePedidosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStonePedidos::route('/'),
        ];
    }
}
