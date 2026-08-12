<?php

namespace App\Filament\Resources\Produtos\RelationManagers;

use App\Enums\ProdutoTipoEnum;
use App\Models\Produto;
use App\Support\CustoUnitarioFormatter;
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
        return in_array($ownerRecord->produto_tipo, [
            ProdutoTipoEnum::PRODUZIDO->value,
            ProdutoTipoEnum::INSUMO_PRODUZIDO->value,
        ], true);
    }

    protected static ?string $modelLabel = 'item';

    protected static ?string $pluralModelLabel = 'itens';

    /** Mesmo cartão rico (foto, categoria, saldo) usado no select de produto de Compras e Balanço. */
    private static function renderOpcaoProduto(Produto $p): string
    {
        $saldoRaw = (float) $p->produto_saldo_estoque;

        return view('filament.components.select-balanco-produto', [
            'image' => $p->produto_foto ? asset('storage/'.$p->produto_foto) : null,
            'name' => $p->nomeExibicao(),
            'category' => $p->categoria?->categoria_nome ?? '',
            'saldo' => number_format($saldoRaw, 3, ',', '.'),
            'saldo_raw' => $saldoRaw,
            'unidade' => $p->produto_unidade_estoque ?? '',
        ])->render();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('fti_insumo_id')
                    ->label('Insumo / Componente')
                    ->placeholder('Digite o nome do produto...')
                    ->searchable()
                    ->allowHtml()
                    ->getSearchResultsUsing(function (string $search): array {
                        $produtoId = $this->getOwnerRecord()->getKey();

                        return Produto::query()
                            ->where('id', '!=', $produtoId) // não pode se referenciar
                            ->where('produto_tipo', '!=', ProdutoTipoEnum::PRODUZIDO->value) // vendido ao cliente, não é insumo de outra ficha
                            ->where('produto_descricao', 'like', "%{$search}%")
                            ->with('categoria')
                            ->orderBy('produto_descricao')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (Produto $p) => [$p->id => self::renderOpcaoProduto($p)])
                            ->toArray();
                    })
                    ->getOptionLabelUsing(fn ($value): ?string => ($p = Produto::with('categoria')->find($value))
                        ? self::renderOpcaoProduto($p)
                        : null)
                    ->native(false)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, $set): void {
                        $insumo = $state ? Produto::find($state) : null;
                        $set('fti_unidade', $insumo?->produto_unidade_estoque);
                    })
                    ->helperText('Pode ser um insumo ou um semi-acabado (com ficha própria).')
                    ->rules([
                        fn (): \Closure => function (string $attribute, $value, \Closure $fail): void {
                            $produtoId = $this->getOwnerRecord()->getKey();
                            $insumo = $value ? Produto::find($value) : null;

                            if ($insumo && $insumo->dependeDe($produtoId)) {
                                $fail("{$insumo->produto_descricao} já depende (direta ou indiretamente) deste produto na ficha técnica — isso criaria um ciclo.");
                            }
                        },
                    ]),

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
                    ->suffix(fn ($record): string => ' '.($record->fti_unidade ?? ''))
                    ->alignEnd(),

                TextColumn::make('fti_percentual_perda')
                    ->label('Perda')
                    ->suffix('%')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('custo_insumo')
                    ->label('Custo Unit. Insumo')
                    ->state(fn ($record): float => $record->insumo ? $record->insumo->custoUnitario() : 0.0)
                    ->formatStateUsing(fn ($state): string => CustoUnitarioFormatter::formatar((float) $state))
                    ->alignEnd(),

                TextColumn::make('custo_item')
                    ->label('Custo no Prato')
                    ->state(fn ($record): float => $record->custo())
                    ->formatStateUsing(fn ($state): string => CustoUnitarioFormatter::formatar((float) $state))
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
