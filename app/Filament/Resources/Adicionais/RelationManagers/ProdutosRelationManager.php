<?php

namespace App\Filament\Resources\Adicionais\RelationManagers;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Support\FaixaValorFilter;
use App\Models\Categoria;
use App\Models\Produto;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Lado inverso do AdicionaisRelationManager do produto: vincula de uma vez
 * vários produtos ao adicional aberto.
 */
class ProdutosRelationManager extends RelationManager
{
    protected static string $relationship = 'produtos';

    protected static ?string $title = 'Produtos';

    protected static ?string $modelLabel = 'produto';

    protected static ?string $pluralModelLabel = 'produtos';

    /** Só o que o cliente compra recebe adicional; insumos e consumo interno ficam de fora. */
    private const TIPOS_VENDAVEIS = [
        ProdutoTipoEnum::PRODUZIDO,
        ProdutoTipoEnum::REVENDA,
    ];

    /** Vincular/desvincular altera o adicional: exige poder editá-lo (o Filament não autoriza attach/detach sozinho). */
    private function podeEditarAdicional(): bool
    {
        return Gate::allows('update', $this->getOwnerRecord());
    }

    /** @return list<string> */
    private static function valoresTiposVendaveis(): array
    {
        return array_map(fn (ProdutoTipoEnum $tipo): string => $tipo->value, self::TIPOS_VENDAVEIS);
    }

    /** Produtos vendáveis que ainda não têm este adicional (vínculo inativado pelo legado não conta). */
    private function produtosVinculaveis(): Builder
    {
        return Produto::query()
            ->whereIn('produto_tipo', self::valoresTiposVendaveis())
            ->whereDoesntHave('adicionais', fn (Builder $query) => $query->whereKey($this->getOwnerRecord()->getKey()));
    }

    /**
     * Categorias que ainda têm produto a vincular, contando as subcategorias,
     * no formato "Pizzas (12)".
     *
     * @return array<int, string>
     */
    private function opcoesCategoria(): array
    {
        $disponiveisPorCategoria = $this->produtosVinculaveis()
            ->selectRaw('produto_categoria_id, COUNT(*) as total')
            ->groupBy('produto_categoria_id')
            ->pluck('total', 'produto_categoria_id');

        $categorias = Categoria::query()->orderBy('categoria_nome')->get(['id', 'categoria_nome', 'categoria_pai_id']);
        $filhasPorPai = $categorias->groupBy('categoria_pai_id');

        $totalComSubcategorias = function (int $categoriaId, array $visitadas = []) use (&$totalComSubcategorias, $filhasPorPai, $disponiveisPorCategoria): int {
            if (in_array($categoriaId, $visitadas, true)) {
                return 0;
            }

            return (int) ($disponiveisPorCategoria[$categoriaId] ?? 0)
                + ($filhasPorPai[$categoriaId] ?? collect())
                    ->sum(fn (Categoria $filha): int => $totalComSubcategorias($filha->id, [...$visitadas, $categoriaId]));
        };

        return $categorias
            ->mapWithKeys(function (Categoria $categoria) use ($totalComSubcategorias): array {
                $total = $totalComSubcategorias($categoria->id);

                return $total > 0 ? [$categoria->id => "{$categoria->categoria_nome} ({$total})"] : [];
            })
            ->all();
    }

    /** @return list<int> */
    private function idsVinculaveisDaCategoria(int $categoriaId): array
    {
        return $this->produtosVinculaveis()
            ->whereIn('produto_categoria_id', Categoria::idsComSubcategorias([$categoriaId]))
            ->pluck('id')
            ->all();
    }

    /** Vincula/desvincula produtos; a edição do produto em si fica no ProdutoResource. */
    public function table(Table $table): Table
    {
        return $table
            // O Filament deduziria "adicionals" (plural em inglês) como relação inversa.
            ->inverseRelationship('adicionais')
            ->recordTitleAttribute('produto_descricao')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('categoria'))
            ->defaultSort('produto_descricao')
            ->emptyStateHeading('Nenhum produto vinculado')
            ->emptyStateDescription('Vincule produtos para oferecer este adicional junto deles.')
            ->emptyStateIcon('heroicon-o-cube')
            ->columns([
                ImageColumn::make('produto_foto')
                    ->label('')
                    // Sem foto vai null: o ImageColumn reprova a URL do padrão (tem espaço) e ficaria quebrada.
                    ->state(fn (Produto $record): ?string => $record->temFoto() ? $record->getMiniaturaUrl(32) : null)
                    ->defaultImageUrl(asset('Sem Imagem.png'))
                    ->circular()
                    ->size(32),

                TextColumn::make('categoria.categoria_nome')
                    ->label('Categoria')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('produto_descricao')
                    ->label('Produto')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('produto_preco_venda')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('produto_tipo')
                    ->label('Tipo')
                    ->formatStateUsing(fn (?string $state): string => ProdutoTipoEnum::tryFrom((string) $state)?->label() ?? '—')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('categoria')
                    ->label('Categoria')
                    ->modifyFormFieldUsing(fn (Select $select): Select => $select->helperText('Inclui as subcategorias.'))
                    ->multiple()
                    ->searchable()
                    // Só as categorias dos produtos já vinculados (e as mães delas).
                    ->options(fn (): array => Categoria::opcoesComAncestrais(
                        $this->getOwnerRecord()->produtos()->distinct()->pluck('produto_categoria_id')->all(),
                    ))
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['values'] ?? null),
                        fn (Builder $q): Builder => $q->whereIn('produto_categoria_id', Categoria::idsComSubcategorias($data['values'])),
                    )),

                SelectFilter::make('produto_tipo')
                    ->label('Tipo')
                    ->options(collect(ProdutoTipoEnum::cases())
                        ->mapWithKeys(fn (ProdutoTipoEnum $tipo): array => [$tipo->value => $tipo->label()])
                        ->all()),

                TernaryFilter::make('produto_cardapio')
                    ->label('No cardápio')
                    ->trueLabel('Só os que aparecem no cardápio')
                    ->falseLabel('Só os fora do cardápio'),

                FaixaValorFilter::make('produto_preco_venda'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Vincular produtos')
                    ->modalHeading('Vincular produtos ao adicional')
                    ->authorize(fn (): bool => $this->podeEditarAdicional())
                    ->schema(fn (AttachAction $action): array => [
                        Select::make('categoria_para_selecionar')
                            ->label('Selecionar todos de uma categoria')
                            ->placeholder('Escolha uma categoria...')
                            ->helperText('Inclui as subcategorias. Os produtos entram na lista abaixo, onde dá pra tirar algum antes de vincular.')
                            ->options(fn (): array => $this->opcoesCategoria())
                            ->searchable()
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                if (blank($state)) {
                                    return;
                                }

                                $set('recordId', array_values(array_unique([
                                    ...array_map('intval', (array) ($get('recordId') ?? [])),
                                    ...$this->idsVinculaveisDaCategoria((int) $state),
                                ])));
                                $set('categoria_para_selecionar', null);
                            }),
                        $action->getRecordSelect(),
                    ])
                    ->preloadRecordSelect()
                    ->multiple()
                    ->recordSelectSearchColumns(['produto_descricao'])
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query
                        ->with('categoria')
                        ->whereIn('produto_tipo', self::valoresTiposVendaveis())
                        ->orderBy('produto_descricao'))
                    ->recordSelect(fn (Select $select): Select => $select
                        ->allowHtml()
                        ->placeholder('Digite o nome do produto...'))
                    ->recordTitle(fn (Produto $record): string => self::renderOpcaoProduto($record)),
            ])
            ->recordActions([
                DetachAction::make()
                    ->label('Desvincular')
                    ->authorize(fn (): bool => $this->podeEditarAdicional()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->label('Desvincular selecionados')
                        ->authorize(fn (): bool => $this->podeEditarAdicional()),
                ]),
            ]);
    }

    /** Cartão da opção do select: foto, categoria, descrição e valor de venda. */
    public static function renderOpcaoProduto(Produto $produto): string
    {
        return view('filament.components.select-balanco-produto', [
            'image' => $produto->produto_foto ? asset('storage/'.$produto->produto_foto) : null,
            'name' => $produto->produto_descricao,
            'category' => $produto->categoria?->categoria_nome ?? '',
            'saldo' => null,
            'saldo_raw' => null,
            'unidade' => null,
            'valor' => 'R$ '.number_format((float) $produto->produto_preco_venda, 2, ',', '.'),
        ])->render();
    }
}
