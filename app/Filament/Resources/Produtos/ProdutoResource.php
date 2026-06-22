<?php

namespace App\Filament\Resources\Produtos;

use App\Filament\Resources\Produtos\Pages\CreateProduto;
use App\Filament\Resources\Produtos\Pages\EditProduto;
use App\Filament\Resources\Produtos\Pages\ListProdutos;
use App\Filament\Resources\Produtos\RelationManagers\FichaItensRelationManager;
use App\Filament\Resources\Produtos\Schemas\ProdutoForm;
use App\Filament\Resources\Produtos\Tables\ProdutosTable;
use App\Models\Produto;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProdutoResource extends Resource
{
    protected static ?string $model = Produto::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'produto_descricao';

    public static function form(Schema $schema): Schema
    {
        return ProdutoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProdutosTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            FichaItensRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProdutos::route('/'),
            'create' => CreateProduto::route('/create'),
            'edit' => EditProduto::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
