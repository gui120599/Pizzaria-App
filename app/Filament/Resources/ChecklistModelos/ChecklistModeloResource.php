<?php

namespace App\Filament\Resources\ChecklistModelos;

use App\Filament\Resources\ChecklistModelos\Pages\CreateChecklistModelo;
use App\Filament\Resources\ChecklistModelos\Pages\EditChecklistModelo;
use App\Filament\Resources\ChecklistModelos\Pages\ListChecklistModelos;
use App\Filament\Resources\ChecklistModelos\Schemas\ChecklistModeloForm;
use App\Filament\Resources\ChecklistModelos\Tables\ChecklistModelosTable;
use App\Models\ChecklistModelo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ChecklistModeloResource extends Resource
{
    protected static ?string $model = ChecklistModelo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static UnitEnum|string|null $navigationGroup = 'Operação';

    protected static ?string $navigationLabel = 'Modelos de checklist';

    protected static ?string $modelLabel = 'modelo de checklist';

    protected static ?string $pluralModelLabel = 'Modelos de checklist';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'nome';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return ChecklistModeloForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChecklistModelosTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChecklistModelos::route('/'),
            'create' => CreateChecklistModelo::route('/create'),
            'edit' => EditChecklistModelo::route('/{record}/edit'),
        ];
    }
}
