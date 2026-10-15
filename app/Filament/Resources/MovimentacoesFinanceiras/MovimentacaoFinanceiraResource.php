<?php

namespace App\Filament\Resources\MovimentacoesFinanceiras;

use App\Filament\Resources\MovimentacoesFinanceiras\Pages\ListMovimentacoesFinanceiras;
use App\Filament\Resources\MovimentacoesFinanceiras\Tables\MovimentacoesFinanceirasTable;
use App\Models\MovimentacaoFinanceira;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Extrato geral das contas financeiras. Somente leitura: corrige-se por estorno. */
class MovimentacaoFinanceiraResource extends Resource
{
    protected static ?string $model = MovimentacaoFinanceira::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Extrato das contas';

    protected static ?string $modelLabel = 'movimentação';

    protected static ?string $pluralModelLabel = 'Extrato das contas';

    protected static ?string $slug = 'extrato-contas';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?int $navigationSort = 13;

    public static function table(Table $table): Table
    {
        return MovimentacoesFinanceirasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMovimentacoesFinanceiras::route('/'),
        ];
    }
}
