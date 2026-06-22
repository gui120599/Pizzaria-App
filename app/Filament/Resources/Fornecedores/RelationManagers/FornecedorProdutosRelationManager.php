<?php

namespace App\Filament\Resources\Fornecedores\RelationManagers;

use App\Models\Produto;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FornecedorProdutosRelationManager extends RelationManager
{
    protected static string $relationship = 'fornecedorProdutos';

    protected static ?string $title = 'Produtos do Fornecedor (de-para)';

    protected static ?string $modelLabel = 'vínculo';

    protected static ?string $pluralModelLabel = 'vínculos';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('fp_produto_id')
                ->label('Insumo (no estoque)')
                ->options(fn (): array => Produto::orderBy('produto_descricao')->pluck('produto_descricao', 'id')->toArray())
                ->searchable()
                ->required()
                ->columnSpanFull(),

            TextInput::make('fp_codigo_fornecedor')
                ->label('Código no fornecedor (cProd)')
                ->helperText('Usado para casar itens da NF/XML automaticamente'),

            TextInput::make('fp_descricao_fornecedor')->label('Descrição na NF'),

            TextInput::make('fp_unidade_compra')->label('Unidade de compra'),

            TextInput::make('fp_fator_conversao')
                ->label('Fator p/ estoque')
                ->numeric()->step(0.0001)->minValue(0.0001)->default(1)
                ->helperText('1 un. de compra = X un. de estoque'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('fp_descricao_fornecedor')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('produto'))
            ->columns([
                TextColumn::make('produto.produto_descricao')->label('Insumo')->searchable(),
                TextColumn::make('fp_codigo_fornecedor')->label('Cód. forn.')->searchable()->placeholder('—'),
                TextColumn::make('fp_descricao_fornecedor')->label('Descrição NF')->placeholder('—')->toggleable(),
                TextColumn::make('fp_unidade_compra')->label('Un.')->placeholder('—'),
                TextColumn::make('fp_fator_conversao')->label('Fator')->numeric(decimalPlaces: 4)->alignEnd(),
            ])
            ->headerActions([
                CreateAction::make()->label('Vincular produto'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
