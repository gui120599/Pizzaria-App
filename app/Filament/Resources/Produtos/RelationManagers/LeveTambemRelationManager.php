<?php

namespace App\Filament\Resources\Produtos\RelationManagers;

use App\Enums\ProdutoTipoEnum;
use App\Models\Produto;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * "Leve também": produtos sugeridos depois que este é lançado (ex.: a pizza
 * sugere refrigerantes), pelo preço normal, no cardápio, mesa, Atender
 * Pedido e Painel do Garçom.
 */
class LeveTambemRelationManager extends RelationManager
{
    protected static string $relationship = 'leveTambem';

    /** Relação autorreferente: sem a inversa explícita o Filament procura Produto::produtos(). */
    protected static ?string $inverseRelationship = 'sugeridoPor';

    protected static ?string $title = 'Leve também';

    protected static ?string $modelLabel = 'sugestão';

    protected static ?string $pluralModelLabel = 'sugestões';

    /** Vincular/desvincular altera o produto: exige poder editá-lo (o Filament não autoriza attach/detach sozinho). */
    private function podeEditarProduto(): bool
    {
        return Gate::allows('update', $this->getOwnerRecord());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('produto_descricao')
            ->reorderable('plt_ordem', $this->podeEditarProduto())
            ->reorderRecordsTriggerAction(fn (Action $action, bool $isReordering): Action => $action->label($isReordering ? 'Concluir ordem' : 'Ordenar'))
            ->description('Sugeridos depois que este produto é lançado no pedido (cardápio, mesa, Atender Pedido e garçom), pelo preço normal. Quem já está no pedido não é sugerido.')
            ->emptyStateHeading('Nenhuma sugestão')
            ->emptyStateDescription('Vincule produtos para sugerir junto deste (ex.: refrigerantes com a pizza).')
            ->emptyStateIcon('heroicon-o-shopping-bag')
            ->columns([
                ImageColumn::make('produto_foto')
                    ->label('')
                    // Sem foto vai null: o ImageColumn reprova a URL do padrão (tem espaço) e ficaria quebrada.
                    ->state(fn (Produto $record): ?string => $record->temFoto() ? $record->getMiniaturaUrl(56) : null)
                    ->defaultImageUrl(asset('Sem Imagem.png'))
                    ->circular()
                    ->size(32),

                TextColumn::make('produto_descricao')
                    ->label('Produto')
                    ->description(fn (Produto $record): ?string => $record->categoria?->categoria_nome)
                    ->searchable(),

                TextColumn::make('produto_preco_venda')
                    ->label('Preço')
                    ->money('BRL'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Adicionar sugestões')
                    ->authorize(fn (): bool => $this->podeEditarProduto())
                    ->multiple()
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query
                        ->whereKeyNot($this->getOwnerRecord()->getKey())
                        ->whereIn('produto_tipo', [ProdutoTipoEnum::PRODUZIDO->value, ProdutoTipoEnum::REVENDA->value])
                        ->with('categoria')
                        ->orderBy('produto_descricao'))
                    ->recordSelectSearchColumns(['produto_descricao'])
                    ->recordTitle(fn (Model $record): string => $record->produto_descricao
                        .($record->categoria ? ' ('.$record->categoria->categoria_nome.')' : '')
                        .' — R$ '.number_format((float) $record->produto_preco_venda, 2, ',', '.')),
            ])
            ->recordActions([
                DetachAction::make()
                    ->label('Remover')
                    ->authorize(fn (): bool => $this->podeEditarProduto()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->label('Remover selecionados')
                        ->authorize(fn (): bool => $this->podeEditarProduto()),
                ]),
            ]);
    }
}
