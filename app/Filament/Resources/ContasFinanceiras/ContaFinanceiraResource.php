<?php

namespace App\Filament\Resources\ContasFinanceiras;

use App\Filament\Resources\ContasFinanceiras\Pages\CreateContaFinanceira;
use App\Filament\Resources\ContasFinanceiras\Pages\EditContaFinanceira;
use App\Filament\Resources\ContasFinanceiras\Pages\ListContasFinanceiras;
use App\Filament\Resources\ContasFinanceiras\Schemas\ContaFinanceiraForm;
use App\Filament\Resources\ContasFinanceiras\Tables\ContasFinanceirasTable;
use App\Models\ContaFinanceira;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/** Onde a empresa guarda dinheiro (caixa PDV, cofre, bancos), com saldo e extrato. */
class ContaFinanceiraResource extends Resource
{
    protected static ?string $model = ContaFinanceira::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Contas financeiras';

    protected static ?string $modelLabel = 'conta financeira';

    protected static ?string $pluralModelLabel = 'Contas financeiras';

    protected static ?string $slug = 'contas-financeiras';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'nome';

    protected static ?int $navigationSort = 12;

    public static function form(Schema $schema): Schema
    {
        return ContaFinanceiraForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContasFinanceirasTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContasFinanceiras::route('/'),
            'create' => CreateContaFinanceira::route('/create'),
            'edit' => EditContaFinanceira::route('/{record}/edit'),
        ];
    }
}
