<?php

namespace App\Filament\Resources\SessoesMesa;

use App\Filament\Resources\SessoesMesa\Pages\CreateSessaoMesa;
use App\Filament\Resources\SessoesMesa\Pages\EditSessaoMesa;
use App\Filament\Resources\SessoesMesa\Pages\ListSessoesMesa;
use App\Filament\Resources\SessoesMesa\RelationManagers\ClientesRelationManager;
use App\Filament\Resources\SessoesMesa\RelationManagers\PedidosRelationManager;
use App\Filament\Resources\SessoesMesa\Schemas\SessaoMesaForm;
use App\Filament\Resources\SessoesMesa\Tables\SessoesMesaTable;
use App\Models\SessaoMesa;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SessaoMesaResource extends Resource
{
    protected static ?string $model = SessaoMesa::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static UnitEnum|string|null $navigationGroup = 'Salão';

    protected static ?string $navigationLabel = 'Sessão da Mesa';

    protected static ?string $modelLabel = 'sessão de mesa';

    protected static ?string $pluralModelLabel = 'sessões de mesa';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return SessaoMesaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SessoesMesaTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PedidosRelationManager::class,
            ClientesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSessoesMesa::route('/'),
            'create' => CreateSessaoMesa::route('/create'),
            'edit' => EditSessaoMesa::route('/{record}/edit'),
        ];
    }
}
