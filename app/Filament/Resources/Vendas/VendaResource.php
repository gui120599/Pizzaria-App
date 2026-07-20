<?php

namespace App\Filament\Resources\Vendas;

use App\Filament\Resources\Vendas\Pages\CreateVenda;
use App\Filament\Resources\Vendas\Pages\EditVenda;
use App\Filament\Resources\Vendas\Pages\ListVendas;
use App\Filament\Resources\Vendas\Schemas\VendaForm;
use App\Filament\Resources\Vendas\Tables\VendasTable;
use App\Models\Venda;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

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

    public static function form(Schema $schema): Schema
    {
        return VendaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VendasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendas::route('/'),
            'create' => CreateVenda::route('/create'),
            'edit' => EditVenda::route('/{record}/edit'),
        ];
    }
}
