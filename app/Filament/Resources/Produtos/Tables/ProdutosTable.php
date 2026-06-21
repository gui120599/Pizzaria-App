<?php

namespace App\Filament\Resources\Produtos\Tables;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\Produto;
use Filament\Actions\Action as ActionsAction;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
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
            ->reorderable('produto_ordem')
            ->defaultSort('produto_ordem')
            ->deferFilters(false)
            ->columns([
                TextColumn::make('produto_ordem')
                    ->label('Ord.')
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('gray')
                    ->width('60px'),

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
                    ->formatStateUsing(fn ($state) => ProdutoTipoEnum::tryFrom($state)?->label() ?? $state)
                    ->color(fn ($state) => match ($state) {
                        ProdutoTipoEnum::PRODUZIDO->value       => 'success',
                        ProdutoTipoEnum::REVENDA->value         => 'info',
                        ProdutoTipoEnum::INSUMO->value          => 'warning',
                        ProdutoTipoEnum::CONSUMO_INTERNO->value => 'danger',
                        default                                 => 'gray',
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

                ToggleColumn::make('produto_cardapio')
                    ->label('Cardápio')
                    ->toggleable(isToggledHiddenByDefault: false),

                ToggleColumn::make('produto_controla_estoque')
                    ->label('Controla Estoque')
                    ->toggleable(isToggledHiddenByDefault: false),

                ToggleColumn::make('produto_destaque_mais_vendidos')
                    ->label('Mais Vendidos')
                    ->toggleable(isToggledHiddenByDefault: false),

                ToggleColumn::make('produto_exibe_categoria')
                    ->label('Exibe Categoria')
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
                    ->multiple()
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
                    ->label('Com Preço Promocional')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('produto_preco_promocional', '>', 0)
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
                ActionsAction::make('ajustar_preco_promocional')
                    ->icon('heroicon-o-tag')
                    ->tooltip('Ajustar preço promocional')
                    ->color('warning')
                    ->modalHeading(fn(Produto $record) => "Preço Promocional — {$record->produto_descricao}")
                    ->modalWidth('md')
                    ->form([
                        ToggleButtons::make('tipo')
                            ->label('Operação')
                            ->options([
                                'valor_exato'          => 'Valor exato',
                                'desconto_percentual'  => 'Desconto %',
                                'desconto_real'        => 'Desconto R$',
                                'remover'              => 'Remover promoção',
                            ])
                            ->icons([
                                'valor_exato'         => 'heroicon-o-pencil',
                                'desconto_percentual' => 'heroicon-o-percent-badge',
                                'desconto_real'       => 'heroicon-o-banknotes',
                                'remover'             => 'heroicon-o-x-circle',
                            ])
                            ->colors([
                                'valor_exato'         => 'info',
                                'desconto_percentual' => 'warning',
                                'desconto_real'       => 'success',
                                'remover'             => 'danger',
                            ])
                            ->inline()
                            ->grouped()
                            ->default('desconto_percentual')
                            ->live()
                            ->required(),

                        TextInput::make('valor')
                            ->label(fn($get) => match($get('tipo')) {
                                'valor_exato'         => 'Preço promocional (R$)',
                                'desconto_percentual' => 'Desconto (%)',
                                default               => 'Desconto (R$)',
                            })
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0.01)
                            ->prefix(fn($get) => $get('tipo') === 'desconto_percentual' ? null : 'R$')
                            ->suffix(fn($get) => $get('tipo') === 'desconto_percentual' ? '%' : null)
                            ->visible(fn($get) => $get('tipo') !== 'remover')
                            ->required(fn($get) => $get('tipo') !== 'remover'),
                    ])
                    ->action(function (Produto $record, array $data) {
                        $precoVenda = (float) $record->produto_preco_venda;
                        $valor      = (float) ($data['valor'] ?? 0);

                        $novoPreco = match($data['tipo']) {
                            'valor_exato'         => round($valor, 2),
                            'desconto_percentual' => round($precoVenda * (1 - $valor / 100), 2),
                            'desconto_real'       => round($precoVenda - $valor, 2),
                            default               => 0,
                        };

                        if ($data['tipo'] !== 'remover' && $novoPreco <= 0) {
                            Notification::make()
                                ->title('Valor inválido')
                                ->body('O preço promocional resultante deve ser maior que zero.')
                                ->danger()
                                ->send();
                            return;
                        }

                        $record->update(['produto_preco_promocional' => $novoPreco]);

                        $msg = $novoPreco > 0
                            ? "Preço promocional definido em R$ " . number_format($novoPreco, 2, ',', '.')
                            : "Promoção removida do produto.";

                        Notification::make()->title('Preço promocional atualizado')->body($msg)->success()->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('ajustar_preco_promocional')
                        ->label('Ajustar Preço Promocional')
                        ->icon('heroicon-o-tag')
                        ->color('warning')
                        ->modalHeading('Ajustar Preço Promocional')
                        ->modalDescription('Aplica a operação nos produtos selecionados.')
                        ->modalWidth('md')
                        ->form([
                            ToggleButtons::make('tipo')
                                ->label('Operação')
                                ->options([
                                    'valor_exato'         => 'Valor exato',
                                    'desconto_percentual' => 'Desconto %',
                                    'desconto_real'       => 'Desconto R$',
                                    'remover'             => 'Remover promoção',
                                ])
                                ->icons([
                                    'valor_exato'         => 'heroicon-o-pencil',
                                    'desconto_percentual' => 'heroicon-o-percent-badge',
                                    'desconto_real'       => 'heroicon-o-banknotes',
                                    'remover'             => 'heroicon-o-x-circle',
                                ])
                                ->colors([
                                    'valor_exato'         => 'info',
                                    'desconto_percentual' => 'warning',
                                    'desconto_real'       => 'success',
                                    'remover'             => 'danger',
                                ])
                                ->inline()
                                ->grouped()
                                ->default('desconto_percentual')
                                ->live()
                                ->required(),

                            TextInput::make('valor')
                                ->label(fn($get) => match($get('tipo')) {
                                    'valor_exato'         => 'Preço promocional (R$)',
                                    'desconto_percentual' => 'Desconto (%)',
                                    default               => 'Desconto (R$)',
                                })
                                ->numeric()
                                ->step(0.01)
                                ->minValue(0.01)
                                ->prefix(fn($get) => $get('tipo') === 'desconto_percentual' ? null : 'R$')
                                ->suffix(fn($get) => $get('tipo') === 'desconto_percentual' ? '%' : null)
                                ->visible(fn($get) => $get('tipo') !== 'remover')
                                ->required(fn($get) => $get('tipo') !== 'remover'),
                        ])
                        ->action(function ($records, array $data) {
                            $tipo        = $data['tipo'];
                            $valor       = (float) ($data['valor'] ?? 0);
                            $atualizados = 0;
                            $ignorados   = 0;

                            foreach ($records as $produto) {
                                $precoVenda = (float) $produto->produto_preco_venda;

                                $novoPreco = match($tipo) {
                                    'valor_exato'         => round($valor, 2),
                                    'desconto_percentual' => round($precoVenda * (1 - $valor / 100), 2),
                                    'desconto_real'       => round($precoVenda - $valor, 2),
                                    default               => 0,
                                };

                                if ($tipo !== 'remover' && $novoPreco <= 0) {
                                    $ignorados++;
                                    continue;
                                }

                                $produto->update(['produto_preco_promocional' => $novoPreco]);
                                $atualizados++;
                            }

                            $msg = $tipo === 'remover'
                                ? "{$atualizados} produto(s) com promoção removida."
                                : "{$atualizados} produto(s) atualizados." . ($ignorados > 0 ? " {$ignorados} ignorado(s) por preço inválido." : '');

                            Notification::make()->title('Preço promocional atualizado')->body($msg)->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('duplicar_para_categoria')
                        ->label('Duplicar para Categoria')
                        ->icon('heroicon-o-document-duplicate')
                        ->color('info')
                        ->modalHeading('Duplicar Produtos para outra Categoria')
                        ->modalDescription('Os produtos selecionados serão copiados para a categoria escolhida. Os originais não serão alterados.')
                        ->modalWidth('md')
                        ->form([
                            Select::make('categoria_id')
                                ->label('Categoria destino')
                                ->options(fn () => Categoria::orderBy('categoria_nome')->pluck('categoria_nome', 'id'))
                                ->searchable()
                                ->preload()
                                ->required(),
                        ])
                        ->action(function ($records, array $data) {
                            $count = 0;
                            foreach ($records as $produto) {
                                $clone = $produto->replicate();
                                $clone->produto_categoria_id = $data['categoria_id'];
                                $clone->produto_qtd_vendas   = 0;
                                $clone->save();
                                $count++;
                            }

                            Notification::make()
                                ->title("{$count} produto(s) duplicado(s) com sucesso.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('alterar_tipo')
                        ->label('Alterar Tipo do Produto')
                        ->icon('heroicon-o-adjustments-horizontal')
                        ->color('gray')
                        ->modalHeading('Alterar Tipo do Produto')
                        ->modalDescription('Define o tipo dos produtos selecionados.')
                        ->modalWidth('md')
                        ->form([
                            Select::make('produto_tipo')
                                ->label('Tipo do produto')
                                ->options(
                                    collect(ProdutoTipoEnum::cases())
                                        ->mapWithKeys(fn ($type) => [$type->value => $type->label()])
                                        ->toArray()
                                )
                                ->native(false)
                                ->required(),
                        ])
                        ->action(function ($records, array $data) {
                            $count = 0;
                            foreach ($records as $produto) {
                                $produto->update(['produto_tipo' => $data['produto_tipo']]);
                                $count++;
                            }

                            $label = ProdutoTipoEnum::from($data['produto_tipo'])->label();

                            Notification::make()
                                ->title('Tipo atualizado')
                                ->body("{$count} produto(s) alterado(s) para \"{$label}\".")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
