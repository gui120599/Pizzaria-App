<?php

namespace App\Filament\Resources\Maquininhas;

use App\Filament\Resources\Maquininhas\Pages\CreateMaquininha;
use App\Filament\Resources\Maquininhas\Pages\EditMaquininha;
use App\Filament\Resources\Maquininhas\Pages\ListMaquininhas;
use App\Filament\Resources\Maquininhas\Schemas\MaquininhaForm;
use App\Filament\Resources\Maquininhas\Tables\MaquininhasTable;
use App\Models\Maquininha;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class MaquininhaResource extends Resource
{
    protected static ?string $model = Maquininha::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Maquininhas';

    protected static ?string $modelLabel = 'maquininha';

    protected static ?string $pluralModelLabel = 'maquininhas';

    protected static ?int $navigationSort = 13;

    public static function form(Schema $schema): Schema
    {
        return MaquininhaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaquininhasTable::configure($table);
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
            'index' => ListMaquininhas::route('/'),
            'create' => CreateMaquininha::route('/create'),
            'edit' => EditMaquininha::route('/{record}/edit'),
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
