<?php

namespace App\Filament\Resources\Adicionais\RelationManagers;

use App\Enums\ProdutoTipoEnum;
use App\Models\Produto;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
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
                    ->state(fn (Produto $record): string => $record->getMiniaturaUrl(32))
                    ->circular()
                    ->size(32),

                TextColumn::make('categoria.categoria_nome')
                    ->label('Categoria')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('produto_descricao')
                    ->label('Produto')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('produto_preco_venda')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Vincular produtos')
                    ->modalHeading('Vincular produtos ao adicional')
                    ->authorize(fn (): bool => $this->podeEditarAdicional())
                    ->preloadRecordSelect()
                    ->multiple()
                    ->recordSelectSearchColumns(['produto_descricao'])
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query
                        ->with('categoria')
                        ->whereIn('produto_tipo', array_map(fn (ProdutoTipoEnum $tipo): string => $tipo->value, self::TIPOS_VENDAVEIS))
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
