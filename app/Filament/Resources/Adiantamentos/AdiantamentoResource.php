<?php

namespace App\Filament\Resources\Adiantamentos;

use App\Filament\Resources\Adiantamentos\Pages\ListAdiantamentos;
use App\Filament\Resources\Adiantamentos\Tables\AdiantamentosTable;
use App\Models\Adiantamento;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Vales e consumo interno, descontados no próximo repasse. */
class AdiantamentoResource extends Resource
{
    protected static ?string $model = Adiantamento::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static UnitEnum|string|null $navigationGroup = 'Ponto e Repasses';

    protected static ?string $navigationLabel = 'Adiantamentos';

    protected static ?string $modelLabel = 'adiantamento';

    protected static ?string $pluralModelLabel = 'Adiantamentos';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return AdiantamentosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdiantamentos::route('/'),
        ];
    }
}
