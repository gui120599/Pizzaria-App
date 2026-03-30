<?php

namespace App\Filament\Resources\Produtos\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProdutosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('produto_descricao')
                    ->searchable(),
                TextColumn::make('produto_tipo')
                    ->searchable(),
                IconColumn::make('produto_controla_estoque')
                    ->boolean(),
                TextColumn::make('produto_codimentacao')
                    ->searchable(),
                IconColumn::make('produto_cardapio')
                    ->boolean(),
                TextColumn::make('produto_codigo_NCM')
                    ->searchable(),
                TextColumn::make('produto_codigo_CEST')
                    ->searchable(),
                TextColumn::make('produto_codigo_EAN')
                    ->searchable(),
                TextColumn::make('produto_codigo_beneficio_fiscal_uf')
                    ->searchable(),
                TextColumn::make('produto_CFOP')
                    ->searchable(),
                TextColumn::make('produto_CSOSN')
                    ->searchable(),
                TextColumn::make('produto_categoria_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('produto_foto')
                    ->searchable(),
                TextColumn::make('produto_unidade_comercial')
                    ->searchable(),
                TextColumn::make('produto_preco_custo')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('produto_valor_percentual_venda')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('produto_preco_venda')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('produto_valor_percentual_comissao')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('produto_preco_comissao')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('produto_preco_promocional')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('produto_cod_origem_mercadoria')
                    ->searchable(),
                TextColumn::make('produto_cod_tributacao_icms')
                    ->searchable(),
                TextColumn::make('produto_valor_percentual_icms')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('produto_valor_percentual_cofins')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('produto_valor_percentual_pis')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('produto_valor_percentual_reducao_icms')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('produto_data_inicio_promocao')
                    ->date()
                    ->sortable(),
                TextColumn::make('produto_data_final_promocao')
                    ->date()
                    ->sortable(),
                TextColumn::make('produto_quantidade_minima')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('produto_quantidade_maxima')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
