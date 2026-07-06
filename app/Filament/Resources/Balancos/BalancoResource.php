<?php

namespace App\Filament\Resources\Balancos;

use App\Filament\Resources\Balancos\Pages\CreateBalanco;
use App\Filament\Resources\Balancos\Pages\ListBalancos;
use App\Filament\Resources\Balancos\Schemas\BalancoForm;
use App\Filament\Resources\Balancos\Tables\BalancosTable;
use App\Models\MovimentacaoBalanco;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BalancoResource extends Resource
{
    protected static ?string $model = MovimentacaoBalanco::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static UnitEnum|string|null $navigationGroup = 'Estoque';

    protected static ?string $navigationLabel = 'Balanços';

    protected static ?string $modelLabel = 'Balanço';

    protected static ?string $pluralModelLabel = 'Balanços';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return BalancoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BalancosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListBalancos::route('/'),
            'create' => CreateBalanco::route('/create'),
        ];
    }
}
