<?php

namespace App\Filament\Resources\Vendas;

use App\Filament\Resources\Vendas\Pages\ListVendas;
use App\Filament\Resources\Vendas\Tables\VendasTable;
use App\Models\Venda;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * CRUD administrativo só para consulta/histórico de vendas (tabela
 * somente leitura, sem create/edit). Operar uma venda (criar, lançar itens,
 * pagamento, finalizar/cancelar) acontece em App\Filament\Pages\OperarVenda —
 * ver Action "Operar" na tabela e o link "Nova venda" no cabeçalho.
 */
class VendaResource extends Resource
{
    protected static ?string $model = Venda::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $navigationLabel = 'Vendas';

    protected static ?string $modelLabel = 'Venda';

    protected static ?string $pluralModelLabel = 'Vendas';

    protected static UnitEnum|string|null $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return VendasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendas::route('/'),
        ];
    }
}
