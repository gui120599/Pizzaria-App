<?php

namespace App\Filament\Resources\FichasTecnicas;

use App\Filament\Resources\FichasTecnicas\Pages\CreateFichaTecnica;
use App\Filament\Resources\FichasTecnicas\Pages\EditFichaTecnica;
use App\Filament\Resources\FichasTecnicas\Pages\ListFichasTecnicas;
use App\Filament\Resources\FichasTecnicas\Schemas\FichaTecnicaForm;
use App\Filament\Resources\FichasTecnicas\Tables\FichasTecnicasTable;
use App\Models\FichaTecnica;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Ficha técnica de preparo (spec §7.1): passos, fotos, alérgenos e o escopo
 * de cada ingrediente para o meio a meio. Os ingredientes são as mesmas
 * linhas da ficha de custo do produto (ficha_tecnica_itens), então editar a
 * quantidade aqui muda também o CMV e a baixa de estoque.
 */
class FichaTecnicaResource extends Resource
{
    protected static ?string $model = FichaTecnica::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Fichas Técnicas';

    protected static ?string $modelLabel = 'ficha técnica';

    protected static ?string $pluralModelLabel = 'fichas técnicas';

    protected static UnitEnum|string|null $navigationGroup = 'Cozinha';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'fichas-tecnicas';

    public static function form(Schema $schema): Schema
    {
        return FichaTecnicaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FichasTecnicasTable::configure($table);
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof FichaTecnica ? ($record->produto?->produto_descricao ?? 'Ficha #'.$record->id) : 'Ficha técnica';
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFichasTecnicas::route('/'),
            'create' => CreateFichaTecnica::route('/create'),
            'edit' => EditFichaTecnica::route('/{record}/edit'),
        ];
    }
}
