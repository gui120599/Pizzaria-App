<?php

namespace App\Filament\Resources\LinhasProducao;

use App\Filament\Resources\LinhasProducao\Pages\CreateLinhaProducao;
use App\Filament\Resources\LinhasProducao\Pages\EditLinhaProducao;
use App\Filament\Resources\LinhasProducao\Pages\ListLinhasProducao;
use App\Filament\Resources\LinhasProducao\Schemas\LinhaProducaoForm;
use App\Filament\Resources\LinhasProducao\Tables\LinhasProducaoTable;
use App\Models\LinhaProducao;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Linhas de produção (estações da cozinha, ex.: "Pizzas", "Bar"), usadas pelo
 * Painel de Pedidos (App\Filament\Pages\PainelPedidos) para filtrar o board
 * por estação — cada linha aponta pra um conjunto de categorias e o filtro
 * mostra pedido com pelo menos um item de alguma delas (ou de uma
 * subcategoria, ver LinhaProducao::idsCategoriasComDescendentes()).
 */
class LinhaProducaoResource extends Resource
{
    protected static ?string $model = LinhaProducao::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Linhas de Produção';

    protected static ?string $modelLabel = 'linha de produção';

    protected static ?string $pluralModelLabel = 'linhas de produção';

    protected static UnitEnum|string|null $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 22;

    protected static ?string $recordTitleAttribute = 'linha_nome';

    public static function form(Schema $schema): Schema
    {
        return LinhaProducaoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LinhasProducaoTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLinhasProducao::route('/'),
            'create' => CreateLinhaProducao::route('/create'),
            'edit' => EditLinhaProducao::route('/{record}/edit'),
        ];
    }
}
