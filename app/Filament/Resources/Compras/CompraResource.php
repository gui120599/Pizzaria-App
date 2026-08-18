<?php

namespace App\Filament\Resources\Compras;

use App\Filament\Resources\Compras\Pages\CreateCompra;
use App\Filament\Resources\Compras\Pages\EditCompra;
use App\Filament\Resources\Compras\Pages\ListCompras;
use App\Filament\Resources\Compras\RelationManagers\CompraDevolucoesRelationManager;
use App\Filament\Resources\Compras\RelationManagers\ItensRelationManager;
use App\Filament\Resources\Compras\RelationManagers\LancamentosRelationManager;
use App\Filament\Resources\Compras\Schemas\CompraForm;
use App\Filament\Resources\Compras\Tables\ComprasTable;
use App\Models\Compra;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CompraResource extends Resource
{
    protected static ?string $model = Compra::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    protected static UnitEnum|string|null $navigationGroup = 'Estoque';

    protected static ?string $navigationLabel = 'Compras / Entrada NF';

    protected static ?string $modelLabel = 'Compra';

    protected static ?string $pluralModelLabel = 'Compras';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'compra_numero';

    public static function form(Schema $schema): Schema
    {
        return CompraForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ComprasTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItensRelationManager::class,
            LancamentosRelationManager::class,
            CompraDevolucoesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompras::route('/'),
            'create' => CreateCompra::route('/create'),
            'edit' => EditCompra::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
