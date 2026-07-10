<?php

namespace App\Filament\Resources\PlanoReceitas;

use App\Filament\Resources\PlanoReceitas\Pages\CreatePlanoReceita;
use App\Filament\Resources\PlanoReceitas\Pages\EditPlanoReceita;
use App\Filament\Resources\PlanoReceitas\Pages\ListPlanoReceitas;
use App\Filament\Resources\PlanoReceitas\Schemas\PlanoReceitaForm;
use App\Filament\Resources\PlanoReceitas\Tables\PlanoReceitasTable;
use App\Models\PlanoReceita;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class PlanoReceitaResource extends Resource
{
    protected static ?string $model = PlanoReceita::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Plano de Receitas';

    protected static ?string $modelLabel = 'conta de receita';

    protected static ?string $pluralModelLabel = 'contas de receita';

    protected static ?int $navigationSort = 21;

    protected static ?string $recordTitleAttribute = 'nome';

    public static function form(Schema $schema): Schema
    {
        return PlanoReceitaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlanoReceitasTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlanoReceitas::route('/'),
            'create' => CreatePlanoReceita::route('/create'),
            'edit' => EditPlanoReceita::route('/{record}/edit'),
        ];
    }
}
