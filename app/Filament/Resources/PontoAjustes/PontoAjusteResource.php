<?php

namespace App\Filament\Resources\PontoAjustes;

use App\Filament\Resources\PontoAjustes\Pages\ListPontoAjustes;
use App\Filament\Resources\PontoAjustes\Tables\PontoAjustesTable;
use App\Models\PontoAjuste;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Fila de ajustes pedidos pelos colaboradores (e histórico das correções). Só se decide; não se edita. */
class PontoAjusteResource extends Resource
{
    protected static ?string $model = PontoAjuste::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static UnitEnum|string|null $navigationGroup = 'Ponto e Repasses';

    protected static ?string $navigationLabel = 'Ajustes de ponto';

    protected static ?string $modelLabel = 'ajuste de ponto';

    protected static ?string $pluralModelLabel = 'Ajustes de ponto';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $pendentes = PontoAjuste::query()->pendentes()->count();

        return $pendentes > 0 ? (string) $pendentes : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return PontoAjustesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPontoAjustes::route('/'),
        ];
    }
}
