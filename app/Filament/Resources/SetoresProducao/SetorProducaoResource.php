<?php

namespace App\Filament\Resources\SetoresProducao;

use App\Filament\Resources\SetoresProducao\Pages\CreateSetorProducao;
use App\Filament\Resources\SetoresProducao\Pages\EditSetorProducao;
use App\Filament\Resources\SetoresProducao\Pages\ListSetoresProducao;
use App\Filament\Resources\SetoresProducao\Schemas\SetorProducaoForm;
use App\Filament\Resources\SetoresProducao\Tables\SetoresProducaoTable;
use App\Models\SetorProducao;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Setores da cozinha (Pizzaria, Cozinha, Bar) e o passe (Expedição). Cada
 * tablet vê só os itens dos seus setores; produtos e categorias apontam para
 * eles pela rota de produção.
 */
class SetorProducaoResource extends Resource
{
    protected static ?string $model = SetorProducao::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFire;

    protected static ?string $navigationLabel = 'Setores de Produção';

    protected static ?string $modelLabel = 'setor de produção';

    protected static ?string $pluralModelLabel = 'setores de produção';

    protected static UnitEnum|string|null $navigationGroup = 'Cozinha';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'nome';

    protected static ?string $slug = 'setores-producao';

    public static function form(Schema $schema): Schema
    {
        return SetorProducaoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SetoresProducaoTable::configure($table);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSetoresProducao::route('/'),
            'create' => CreateSetorProducao::route('/create'),
            'edit' => EditSetorProducao::route('/{record}/edit'),
        ];
    }
}
