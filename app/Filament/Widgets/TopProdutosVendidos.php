<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use App\Models\ItensVenda;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

class TopProdutosVendidos extends BaseWidget
{
    use InteractsComPeriodo;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        [$inicio, $fim] = $this->periodo();

        return $table
            ->heading('Produtos mais vendidos no período')
            ->description('Receita e margem por produto, com o custo congelado no momento da venda.')
            ->query(
                // Agrega numa subquery e expõe como tabela derivada plana.
                // Assim o Filament (ordenação por chave, count de paginação)
                // opera sobre colunas simples, sem violar only_full_group_by.
                ItensVenda::query()->fromSub(
                    $this->itensFiltradosQuery($inicio, $fim)
                        ->leftJoin('categorias', 'categorias.id', '=', 'produtos.produto_categoria_id')
                        ->groupBy(
                            'itens_vendas.item_venda_produto_id',
                            'produtos.produto_descricao',
                            'produtos.produto_foto',
                            'categorias.categoria_nome',
                        )
                        ->selectRaw('
                            itens_vendas.item_venda_produto_id as id,
                            produtos.produto_descricao as produto,
                            produtos.produto_foto as foto,
                            COALESCE(categorias.categoria_nome, "Sem categoria") as categoria,
                            SUM(itens_vendas.item_venda_quantidade) as qtd,
                            SUM(itens_vendas.item_venda_valor) as receita,
                            SUM(itens_vendas.item_venda_quantidade * itens_vendas.item_venda_custo_unitario) as custo
                        '),
                    'itens_vendas',
                )
            )
            ->columns([
                ImageColumn::make('foto')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->size(40)
                    ->defaultImageUrl(asset('Sem Imagem.png')),

                TextColumn::make('produto')
                    ->label('Produto')
                    ->weight(\Filament\Support\Enums\FontWeight::SemiBold)
                    ->wrap(),

                TextColumn::make('categoria')
                    ->label('Categoria')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('qtd')
                    ->label('Qtd. vendida')
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('receita')
                    ->label('Receita')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('custo')
                    ->label('Custo (CMV)')
                    ->money('BRL')
                    ->alignEnd()
                    ->color('gray'),

                TextColumn::make('margem')
                    ->label('Margem')
                    ->state(function ($record): string {
                        $receita = (float) $record->receita;
                        $custo = (float) $record->custo;
                        if ($receita <= 0) {
                            return '—';
                        }
                        $pct = ($receita - $custo) / $receita * 100;

                        return number_format($pct, 1, ',', '.').'%';
                    })
                    ->badge()
                    ->color(function ($record): string {
                        $receita = (float) $record->receita;
                        $custo = (float) $record->custo;
                        if ($receita <= 0) {
                            return 'gray';
                        }
                        $pct = ($receita - $custo) / $receita * 100;

                        return $pct >= 60 ? 'success' : ($pct >= 40 ? 'warning' : 'danger');
                    })
                    ->alignEnd(),
            ])
            ->defaultSort('receita', 'desc')
            ->paginated([10, 25, 50]);
    }
}
