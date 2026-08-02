<?php

namespace App\Filament\Resources\Compras\RelationManagers;

use App\Filament\Components\MarcaSelect;
use App\Filament\Resources\Produtos\Schemas\ProdutoForm;
use App\Models\Compra;
use App\Models\FornecedorProduto;
use App\Models\Produto;
use App\Services\CompraService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ItensRelationManager extends RelationManager
{
    protected static string $relationship = 'itens';

    protected static ?string $title = 'Itens da compra';

    protected static ?string $modelLabel = 'item';

    protected static ?string $pluralModelLabel = 'itens';

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

    private function rascunho(): bool
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Compra && $owner->isRascunho();
    }

    private static function produtoControlaLote(Get $get): bool
    {
        return (bool) optional(Produto::find($get('ci_produto_id')))->produto_controla_lote;
    }

    private static function produtoRastreiaLote(Get $get): bool
    {
        return (bool) optional(Produto::find($get('ci_produto_id')))->rastreiaLote();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('ci_produto_id')
                    ->label('Buscar produto / insumo')
                    ->placeholder('Digite o nome do produto...')
                    ->searchable()
                    ->allowHtml()
                    ->getSearchResultsUsing(fn (string $search): array => Produto::query()
                        ->where(function (Builder $q) use ($search): void {
                            $q->where('produto_descricao', 'like', "%{$search}%")
                                ->orWhereHas('categoria', fn (Builder $c) => $c->where('categoria_nome', 'like', "%{$search}%"));
                        })
                        ->with('categoria')
                        ->orderBy('produto_descricao')
                        ->limit(30)
                        ->get()
                        ->mapWithKeys(fn (Produto $p) => [$p->id => self::renderOpcaoProduto($p)])
                        ->toArray())
                    ->getOptionLabelUsing(fn ($value): ?string => ($p = Produto::with('categoria')->find($value))
                        ? self::renderOpcaoProduto($p)
                        : null)
                    ->createOptionForm(fn (Schema $schema) => ProdutoForm::configure($schema))
                    ->createOptionAction(
                        fn (Action $action) => $action
                            ->modalHeading('Cadastrar novo produto')
                            ->slideOver()
                    )
                    ->createOptionUsing(function (array $data): int {
                        return Produto::create($data)->getKey();
                    })
                    ->live()
                    ->columnSpanFull()
                    ->helperText('Deixe em branco para mapear depois — o item fica pendente até você indicar o produto.')
                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                        $produto = $state ? Produto::find($state) : null;
                        if (! $produto) {
                            return;
                        }

                        // Não sobrescreve dados que já vieram preenchidos (ex.: item
                        // importado de XML, mapeando o produto só agora).
                        if (blank($get('ci_descricao_fornecedor'))) {
                            $set('ci_descricao_fornecedor', $produto->produto_descricao);
                        }
                        if (blank($get('ci_unidade_compra'))) {
                            $set('ci_unidade_compra', $produto->produto_unidade_estoque);
                        }
                        if ((float) ($get('ci_custo_unitario_compra') ?? 0) <= 0) {
                            $set('ci_custo_unitario_compra', (float) $produto->produto_custo_medio);
                        }

                        // Prefill pelo de-para do fornecedor, se existir.
                        $owner = $this->getOwnerRecord();
                        if ($owner?->compra_prestador_id) {
                            $dp = FornecedorProduto::where('fp_prestador_id', $owner->compra_prestador_id)
                                ->where('fp_produto_id', $produto->id)
                                ->first();
                            if ($dp) {
                                if (blank($get('ci_codigo_fornecedor'))) {
                                    $set('ci_codigo_fornecedor', $dp->fp_codigo_fornecedor);
                                }
                                if (blank($get('ci_unidade_compra'))) {
                                    $set('ci_unidade_compra', $dp->fp_unidade_compra ?: $produto->produto_unidade_estoque);
                                }
                                $set('ci_fator_conversao', (float) $dp->fp_fator_conversao ?: 1);
                            }
                        }
                    }),

                TextInput::make('ci_quantidade_compra')
                    ->label('Quantidade comprada')
                    ->numeric()->step(0.0001)->minValue(0.0001)->required()->default(1),

                Money::make('ci_custo_unitario_compra')
                    ->label('Custo por unidade de compra')
                    ->minValue(0)
                    ->required()
                    ->formatStateUsing(fn ($state) => number_format((float) ($state ?? 0), 2, ',', '.')),

                TextInput::make('ci_unidade_compra')
                    ->label('Unidade de compra')
                    ->placeholder('CX, KG, UN...'),

                TextInput::make('ci_fator_conversao')
                    ->label('Fator para o estoque')
                    ->numeric()->step(0.0001)->minValue(0.0001)->default(1)->required()
                    ->helperText('1 unidade de compra = X unidades de estoque (ex.: 1 CX = 10 KG → 10)'),

                TextInput::make('ci_lote_codigo')
                    ->label('Lote')
                    ->visible(fn (Get $get): bool => self::produtoControlaLote($get))
                    ->required(fn (Get $get): bool => self::produtoControlaLote($get)),

                DatePicker::make('ci_validade')
                    ->label('Validade')
                    ->visible(fn (Get $get): bool => self::produtoControlaLote($get))
                    ->required(fn (Get $get): bool => self::produtoControlaLote($get)),

                TextInput::make('ci_codigo_fornecedor')->label('Código no fornecedor')->columnSpan(1),
                MarcaSelect::make('ci_marca_id')
                    ->columnSpan(1)
                    ->visible(fn (Get $get): bool => self::produtoRastreiaLote($get))
                    ->helperText('Rastreabilidade da compra/lote — o insumo no estoque continua único.'),
                TextInput::make('ci_descricao_fornecedor')->label('Descrição na NF')->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('ci_descricao_fornecedor')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['insumo', 'marca']))
            ->emptyStateHeading('Nenhum item ainda')
            ->emptyStateDescription('Clique em "Adicionar item" e busque o produto pelo nome.')
            ->emptyStateIcon('heroicon-o-magnifying-glass')
            ->columns([
                ImageColumn::make('insumo.produto_foto')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->size(44)
                    ->defaultImageUrl(asset('Sem Imagem.png')),

                TextColumn::make('insumo.produto_descricao')
                    ->label('Produto')
                    ->weight(\Filament\Support\Enums\FontWeight::SemiBold)
                    ->placeholder('⚠️ Não mapeado — clique em editar')
                    ->color(fn ($record): ?string => $record->ci_produto_id ? null : 'danger')
                    ->description(fn ($record): ?string => $record->ci_codigo_fornecedor ? 'Cód. forn.: '.$record->ci_codigo_fornecedor : null)
                    ->searchable(),

                TextColumn::make('marca.marca_nome')
                    ->label('Marca')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('ci_quantidade_compra')
                    ->label('Qtd.')
                    ->numeric(decimalPlaces: 4)
                    ->suffix(fn ($record): string => ' '.($record->ci_unidade_compra ?? ''))
                    ->alignEnd(),

                TextColumn::make('ci_custo_unitario_compra')
                    ->label('Custo unit.')
                    ->money('BRL')
                    ->alignEnd(),

                TextColumn::make('total_item')
                    ->label('Total')
                    ->state(fn ($record): float => $record->valorProdutos())
                    ->money('BRL')
                    ->alignEnd()
                    ->weight(\Filament\Support\Enums\FontWeight::Bold),

                TextColumn::make('estoque')
                    ->label('Entra no estoque')
                    ->state(fn ($record): string => number_format($record->quantidadeEstoque(), 3, ',', '.')
                        .' '.(optional($record->insumo)->produto_unidade_estoque ?? ''))
                    ->alignEnd()
                    ->color('gray'),

                TextColumn::make('ci_validade')
                    ->label('Validade')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar item')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Buscar e adicionar item')
                    ->modalWidth('xl')
                    ->visible(fn (): bool => $this->rascunho())
                    ->after(fn () => app(CompraService::class)->recalcularTotais($this->getOwnerRecord()->load('itens'))),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->rascunho())
                    ->after(fn () => app(CompraService::class)->recalcularTotais($this->getOwnerRecord()->load('itens'))),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->rascunho())
                    ->after(fn () => app(CompraService::class)->recalcularTotais($this->getOwnerRecord()->load('itens'))),
            ]);
    }
}
