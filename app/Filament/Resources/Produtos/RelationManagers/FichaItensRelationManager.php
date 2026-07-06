<?php

namespace App\Filament\Resources\Produtos\RelationManagers;

use App\Enums\ProdutoTipoEnum;
use App\Models\Produto;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FichaItensRelationManager extends RelationManager
{
    protected static string $relationship = 'fichaItens';

    protected static ?string $title = 'Ficha Técnica';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->produto_tipo === ProdutoTipoEnum::PRODUZIDO->value;
    }

    protected static ?string $modelLabel = 'item';

    protected static ?string $pluralModelLabel = 'itens';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('fti_insumo_id')
                    ->label('Insumo / Componente')
                    ->options(function (): array {
                        $produtoId = $this->getOwnerRecord()->getKey();

                        return Produto::query()
                            ->where('id', '!=', $produtoId) // não pode se referenciar
                            ->orderBy('produto_descricao')
                            ->pluck('produto_descricao', 'id')
                            ->toArray();
                    })
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, $set): void {
                        $insumo = $state ? Produto::find($state) : null;
                        $set('fti_unidade', $insumo?->produto_unidade_estoque);
                    })
                    ->helperText('Pode ser um insumo ou um semi-acabado (com ficha própria).'),

                TextInput::make('fti_quantidade')
                    ->label('Quantidade')
                    ->numeric()
                    ->step(0.0001)
                    ->minValue(0.0001)
                    ->required()
                    ->suffix(fn (Get $get): string => (string) (Produto::find($get('fti_insumo_id'))?->produto_unidade_estoque ?? '')),

                TextInput::make('fti_unidade')
                    ->label('Unidade')
                    ->maxLength(20)
                    ->helperText('Preenchida pela unidade de estoque do insumo'),

                TextInput::make('fti_percentual_perda')
                    ->label('Perda no preparo (%)')
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0)
                    ->default(0)
                    ->suffix('%')
                    ->helperText('Fator de correção: aumenta o consumo do insumo'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('insumo.produto_descricao')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('insumo'))
            ->columns([
                TextColumn::make('insumo.produto_descricao')
                    ->label('Insumo')
                    ->searchable()
                    ->description(fn ($record): ?string => $record->insumo?->temFichaTecnica() ? 'Semi-acabado' : null),

                TextColumn::make('fti_quantidade')
                    ->label('Qtd.')
                    ->numeric(decimalPlaces: 4)
                    ->suffix(fn ($record): string => ' ' . ($record->fti_unidade ?? ''))
                    ->alignEnd(),

                TextColumn::make('fti_percentual_perda')
                    ->label('Perda')
                    ->suffix('%')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('custo_insumo')
                    ->label('Custo Unit. Insumo')
                    ->state(fn ($record): float => $record->insumo ? $record->insumo->custoUnitario() : 0.0)
                    ->money('BRL')
                    ->alignEnd(),

                TextColumn::make('custo_item')
                    ->label('Custo no Prato')
                    ->state(fn ($record): float => $record->custo())
                    ->money('BRL')
                    ->alignEnd()
                    ->weight('bold'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar item'),
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
