<?php

namespace App\Filament\Resources\FichasTecnicas\Tables;

use App\Filament\Resources\FichasTecnicas\Support\PreVisualizarFichaTecnicaAction;
use App\Models\Categoria;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FichasTecnicasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['produto:id,produto_descricao,produto_categoria_id,produto_foto', 'produto.categoria:id,categoria_nome'])
                ->withCount([
                    'passos',
                    'ingredientes',
                    'producaoItens as usos_30d_count' => fn (Builder $q) => $q->where('created_at', '>=', now()->subDays(30)),
                ]))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                ImageColumn::make('produto.produto_foto')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->size(36)
                    ->defaultImageUrl(asset('Sem Imagem.png')),
                TextColumn::make('produto.produto_descricao')
                    ->label('Produto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('produto.categoria.categoria_nome')
                    ->label('Tamanho / categoria')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('versao')
                    ->label('Versão')
                    ->prefix('v')
                    ->alignCenter(),
                TextColumn::make('ingredientes_count')
                    ->label('Ingredientes')
                    ->alignCenter(),
                TextColumn::make('passos_count')
                    ->label('Passos')
                    ->alignCenter(),
                TextColumn::make('usos_30d_count')
                    ->label('Usada (30 dias)')
                    ->suffix('×')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('tempo_preparo_min')
                    ->label('Preparo')
                    ->suffix(' min')
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('ativo')
                    ->label('Ativa')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Atualizada')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('categoria')
                    ->label('Tamanho / categoria')
                    ->options(fn (): array => Categoria::orderBy('categoria_nome')->pluck('categoria_nome', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $categoria) => $q->whereHas('produto', fn (Builder $p) => $p->where('produto_categoria_id', $categoria)),
                    ))
                    ->searchable(),
                TernaryFilter::make('ativo')
                    ->label('Ativa'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                PreVisualizarFichaTecnicaAction::make()
                    ->iconButton()
                    ->tooltip('Pré-visualizar no tablet'),
                EditAction::make(),
                RestoreAction::make(),
            ]);
    }
}
