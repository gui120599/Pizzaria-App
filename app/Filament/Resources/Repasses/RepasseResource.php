<?php

namespace App\Filament\Resources\Repasses;

use App\Filament\Resources\Repasses\Pages\EditRepasse;
use App\Filament\Resources\Repasses\Pages\ListRepasses;
use App\Filament\Resources\Repasses\Pages\ViewRepasse;
use App\Filament\Resources\Repasses\Schemas\RepasseForm;
use App\Filament\Resources\Repasses\Schemas\RepasseInfolist;
use App\Filament\Resources\Repasses\Tables\RepassesTable;
use App\Models\Repasse;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Repasse do colaborador no período. Nasce no fechamento do período; edição só de itens manuais no rascunho. */
class RepasseResource extends Resource
{
    protected static ?string $model = Repasse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static UnitEnum|string|null $navigationGroup = 'Ponto e Repasses';

    protected static ?string $navigationLabel = 'Repasses';

    protected static ?string $modelLabel = 'repasse';

    protected static ?string $pluralModelLabel = 'Repasses';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return RepasseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RepasseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RepassesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRepasses::route('/'),
            'view' => ViewRepasse::route('/{record}'),
            'edit' => EditRepasse::route('/{record}/edit'),
        ];
    }
}
