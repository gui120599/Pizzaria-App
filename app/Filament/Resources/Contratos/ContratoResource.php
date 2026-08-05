<?php

namespace App\Filament\Resources\Contratos;

use App\Filament\Resources\Contratos\Pages\CreateContrato;
use App\Filament\Resources\Contratos\Pages\EditContrato;
use App\Filament\Resources\Contratos\Pages\ListContratos;
use App\Filament\Resources\Contratos\RelationManagers\LancamentosRelationManager;
use App\Filament\Resources\Contratos\Schemas\ContratoForm;
use App\Filament\Resources\Contratos\Tables\ContratosTable;
use App\Models\Contrato;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ContratoResource extends Resource
{
    protected static ?string $model = Contrato::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Contratos';

    protected static ?string $modelLabel = 'contrato';

    protected static ?string $pluralModelLabel = 'contratos';

    protected static ?int $navigationSort = 15;

    protected static ?string $recordTitleAttribute = 'descricao';

    public static function form(Schema $schema): Schema
    {
        return ContratoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContratosTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LancamentosRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContratos::route('/'),
            'create' => CreateContrato::route('/create'),
            'edit' => EditContrato::route('/{record}/edit'),
        ];
    }
}
