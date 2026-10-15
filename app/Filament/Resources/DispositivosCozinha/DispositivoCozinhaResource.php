<?php

namespace App\Filament\Resources\DispositivosCozinha;

use App\Filament\Resources\DispositivosCozinha\Pages\ManageDispositivosCozinha;
use App\Filament\Resources\DispositivosCozinha\Schemas\DispositivoCozinhaForm;
use App\Filament\Resources\DispositivosCozinha\Tables\DispositivosCozinhaTable;
use App\Models\DispositivoCozinha;
use App\Support\Cozinha\ModuloCozinha;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Tablets da cozinha: setores que cada um mostra, modo de exibição e o
 * pareamento por QR (uso único). O tablet entra na conta técnica da estação,
 * criada no primeiro pareamento.
 */
class DispositivoCozinhaResource extends Resource
{
    protected static ?string $model = DispositivoCozinha::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDeviceTablet;

    protected static ?string $navigationLabel = 'Tablets da Cozinha';

    protected static ?string $modelLabel = 'tablet';

    protected static ?string $pluralModelLabel = 'tablets da cozinha';

    protected static UnitEnum|string|null $navigationGroup = 'Cozinha';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'nome';

    protected static ?string $slug = 'tablets-cozinha';

    /** Parear tablet só faz sentido com o módulo ligado (cozinha.ativo). */
    public static function canAccess(): bool
    {
        return ModuloCozinha::ativo() && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return DispositivoCozinhaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DispositivosCozinhaTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDispositivosCozinha::route('/'),
        ];
    }
}
