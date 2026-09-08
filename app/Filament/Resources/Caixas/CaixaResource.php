<?php

namespace App\Filament\Resources\Caixas;

use App\Filament\Resources\Caixas\Pages\CreateCaixa;
use App\Filament\Resources\Caixas\Pages\EditCaixa;
use App\Filament\Resources\Caixas\Pages\ListCaixas;
use App\Filament\Resources\Caixas\Schemas\CaixaForm;
use App\Filament\Resources\Caixas\Tables\CaixasTable;
use App\Models\Caixa;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CaixaResource extends Resource
{
    protected static ?string $model = Caixa::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Caixas';

    protected static ?string $modelLabel = 'caixa';

    protected static ?string $pluralModelLabel = 'caixas';

    protected static ?int $navigationSort = 9;

    public static function form(Schema $schema): Schema
    {
        return CaixaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CaixasTable::configure($table);
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
            'index' => ListCaixas::route('/'),
            'create' => CreateCaixa::route('/create'),
            'edit' => EditCaixa::route('/{record}/edit'),
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
