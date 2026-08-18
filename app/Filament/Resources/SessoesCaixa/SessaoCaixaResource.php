<?php

namespace App\Filament\Resources\SessoesCaixa;

use App\Filament\Resources\SessoesCaixa\Pages\CreateSessaoCaixa;
use App\Filament\Resources\SessoesCaixa\Pages\EditSessaoCaixa;
use App\Filament\Resources\SessoesCaixa\Pages\ListSessoesCaixa;
use App\Filament\Resources\SessoesCaixa\RelationManagers\MovimentacoesRelationManager;
use App\Filament\Resources\SessoesCaixa\RelationManagers\VendasRelationManager;
use App\Filament\Resources\SessoesCaixa\Schemas\SessaoCaixaForm;
use App\Filament\Resources\SessoesCaixa\Tables\SessoesCaixaTable;
use App\Models\SessaoCaixa;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SessaoCaixaResource extends Resource
{
    protected static ?string $model = SessaoCaixa::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Sessão de Caixa';

    protected static ?string $modelLabel = 'sessão de caixa';

    protected static ?string $pluralModelLabel = 'sessões de caixa';

    protected static ?int $navigationSort = 11;

    public static function form(Schema $schema): Schema
    {
        return SessaoCaixaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SessoesCaixaTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            VendasRelationManager::class,
            MovimentacoesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSessoesCaixa::route('/'),
            'create' => CreateSessaoCaixa::route('/create'),
            'edit' => EditSessaoCaixa::route('/{record}/edit'),
        ];
    }
}
