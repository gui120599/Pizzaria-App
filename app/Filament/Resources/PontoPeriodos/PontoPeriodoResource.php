<?php

namespace App\Filament\Resources\PontoPeriodos;

use App\Filament\Resources\PontoPeriodos\Pages\EditPontoPeriodo;
use App\Filament\Resources\PontoPeriodos\Pages\ListPontoPeriodos;
use App\Filament\Resources\PontoPeriodos\RelationManagers\RepassesRelationManager;
use App\Filament\Resources\PontoPeriodos\Schemas\PontoPeriodoForm;
use App\Filament\Resources\PontoPeriodos\Tables\PontoPeriodosTable;
use App\Models\PontoPeriodo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Períodos de apuração e o fechamento (aberto → em conferência → fechado → pago). */
class PontoPeriodoResource extends Resource
{
    protected static ?string $model = PontoPeriodo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static UnitEnum|string|null $navigationGroup = 'Ponto e Repasses';

    protected static ?string $navigationLabel = 'Períodos';

    protected static ?string $modelLabel = 'período';

    protected static ?string $pluralModelLabel = 'Períodos';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return PontoPeriodoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PontoPeriodosTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RepassesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPontoPeriodos::route('/'),
            'edit' => EditPontoPeriodo::route('/{record}'),
        ];
    }
}
