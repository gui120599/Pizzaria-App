<?php

namespace App\Filament\Resources\Colaboradores;

use App\Filament\Resources\Colaboradores\Pages\CreateColaborador;
use App\Filament\Resources\Colaboradores\Pages\EditColaborador;
use App\Filament\Resources\Colaboradores\Pages\ListColaboradores;
use App\Filament\Resources\Colaboradores\RelationManagers\AdiantamentosRelationManager;
use App\Filament\Resources\Colaboradores\RelationManagers\EscalasRelationManager;
use App\Filament\Resources\Colaboradores\RelationManagers\RemuneracoesRelationManager;
use App\Filament\Resources\Colaboradores\Schemas\ColaboradorForm;
use App\Filament\Resources\Colaboradores\Tables\ColaboradoresTable;
use App\Models\Colaborador;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/** Colaboradores da folha de ponto (o espelho deles no financeiro é mantido pelo ColaboradorObserver). */
class ColaboradorResource extends Resource
{
    protected static ?string $model = Colaborador::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static UnitEnum|string|null $navigationGroup = 'Pessoas';

    protected static ?string $navigationLabel = 'Colaboradores';

    protected static ?string $modelLabel = 'colaborador';

    protected static ?string $pluralModelLabel = 'Colaboradores';

    protected static ?string $slug = 'colaboradores';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'nome';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return ColaboradorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ColaboradoresTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RemuneracoesRelationManager::class,
            EscalasRelationManager::class,
            AdiantamentosRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListColaboradores::route('/'),
            'create' => CreateColaborador::route('/create'),
            'edit' => EditColaborador::route('/{record}/edit'),
        ];
    }
}
