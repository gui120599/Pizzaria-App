<?php

namespace App\Filament\Resources\Produtos\Tables;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProdutosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('produto_foto')
                    ->disk('public')
                    ->size(40)
                    ->toggleable(isToggledHiddenByDefault: false),
                
                TextColumn::make('produto_descricao')
                    ->label('Descrição')
                    ->sortable()
                    ->searchable()
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: false),
                
                TextColumn::make('categoria.categoria_nome')
                    ->label('Categoria')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                
                TextColumn::make('produto_tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn($state) => match($state) {
                        'simples' => 'info',
                        'composio' => 'warning',
                        'servico' => 'success',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: false),
                
                TextColumn::make('produto_unidade_comercial')
                    ->label('Unidade')
                    ->toggleable(isToggledHiddenByDefault: false),
                
                TextColumn::make('produto_preco_custo')
                    ->label('Custo')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                
                TextColumn::make('produto_preco_venda')
                    ->label('Venda')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                
                TextColumn::make('produto_valor_percentual_venda')
                    ->label('Margem %')
                    ->numeric(decimalPlaces: 2)
                    ->suffix('%')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('produto_preco_promocional')
                    ->label('Preço Promo')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                IconColumn::make('produto_cardapio')
                    ->label('Cardápio')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: false),
                
                IconColumn::make('produto_controla_estoque')
                    ->label('Controla Estoque')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: false),
                
                TextColumn::make('produto_quantidade_minima')
                    ->label('Qtd. Mín.')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('produto_quantidade_maxima')
                    ->label('Qtd. Máx.')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('produto_codimentacao')
                    ->label('Cod. Alimentação')
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('produto_codigo_EAN')
                    ->label('EAN')
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('produto_codigo_NCM')
                    ->label('NCM')
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('produto_codigo_CEST')
                    ->label('CEST')
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('deleted_at')
                    ->label('Deletado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('produto_categoria_id')
                    ->label('Categoria')
                    ->relationship('categoria', 'categoria_nome')
                    ->searchable()
                    ->preload(),
                
                SelectFilter::make('produto_tipo')
                    ->label('Tipo')
                    ->options(
                        collect(ProdutoTipoEnum::cases())
                            ->mapWithKeys(fn($type) => [$type->value => $type->label()])
                            ->toArray()
                    ),
                
                Filter::make('preco_venda')
                    ->label('Intervalo de Preço')
                    ->form([
                        TextInput::make('preco_venda_min')
                            ->label('Preço mínimo')
                            ->numeric()
                            ->placeholder('0.00'),
                        TextInput::make('preco_venda_max')
                            ->label('Preço máximo')
                            ->numeric()
                            ->placeholder('999.99'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['preco_venda_min'] ?? null,
                                fn (Builder $q, $price) => $q->where('produto_preco_venda', '>=', $price),
                            )
                            ->when(
                                $data['preco_venda_max'] ?? null,
                                fn (Builder $q, $price) => $q->where('produto_preco_venda', '<=', $price),
                            );
                    }),
                
                Filter::make('promocao_ativa')
                    ->label('Com Promoção Ativa')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('produto_data_inicio_promocao')
                        ->whereNotNull('produto_data_final_promocao')
                        ->whereDate('produto_data_inicio_promocao', '<=', now())
                        ->whereDate('produto_data_final_promocao', '>=', now())
                    )
                    ->toggle(),
                
                Filter::make('cardapio')
                    ->label('Mostrar no Cardápio')
                    ->query(fn (Builder $query): Builder => $query->where('produto_cardapio', true))
                    ->toggle(),
                
                Filter::make('controla_estoque')
                    ->label('Controla Estoque')
                    ->query(fn (Builder $query): Builder => $query->where('produto_controla_estoque', true))
                    ->toggle(),
                
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
