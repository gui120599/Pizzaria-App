<?php

namespace App\Filament\Resources\Escalas;

use App\Filament\Resources\Escalas\Pages\CreateEscala;
use App\Filament\Resources\Escalas\Pages\EditEscala;
use App\Filament\Resources\Escalas\Pages\ListEscalas;
use App\Filament\Resources\Escalas\Schemas\EscalaForm;
use App\Filament\Resources\Escalas\Tables\EscalasTable;
use App\Models\Escala;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/** Escalas de trabalho (dias, horários, intervalo, folgas). A vigência por colaborador fica no cadastro dele. */
class EscalaResource extends Resource
{
    protected static ?string $model = Escala::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static UnitEnum|string|null $navigationGroup = 'Pessoas';

    protected static ?string $navigationLabel = 'Escalas';

    protected static ?string $modelLabel = 'escala';

    protected static ?string $pluralModelLabel = 'Escalas';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'nome';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return EscalaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EscalasTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEscalas::route('/'),
            'create' => CreateEscala::route('/create'),
            'edit' => EditEscala::route('/{record}/edit'),
        ];
    }
}
