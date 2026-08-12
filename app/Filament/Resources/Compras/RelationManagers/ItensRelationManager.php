<?php

namespace App\Filament\Resources\Compras\RelationManagers;

use App\Enums\MovimentacaoOrigemEnum;
use App\Filament\Components\MarcaSelect;
use App\Filament\Resources\Produtos\Schemas\ProdutoForm;
use App\Filament\Support\CorrecaoEstoquePreview;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\FornecedorProduto;
use App\Models\MovimentacaoProduto;
use App\Models\Produto;
use App\Services\CompraService;
use App\Services\CorrecaoEstoqueService;
use App\Support\CustoUnitarioFormatter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

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

                TextInput::make('ci_custo_unitario_compra')
                    ->label('Custo por unidade de compra')
                    ->numeric()
                    ->step(0.00000001)
                    ->minValue(0)
                    ->prefix('R$')
                    ->required()
                    ->helperText('Aceita até 8 casas decimais (ex.: 0,00950475).'),

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
                    ->weight(FontWeight::SemiBold)
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
                    ->formatStateUsing(fn ($state): string => CustoUnitarioFormatter::formatar((float) $state))
                    ->alignEnd(),

                TextColumn::make('total_item')
                    ->label('Total')
                    ->state(fn ($record): float => $record->valorProdutos())
                    ->money('BRL')
                    ->alignEnd()
                    ->weight(FontWeight::Bold),

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
                self::acaoCorrigirMovimentacao(),
            ]);
    }

    /**
     * Corrige a quantidade/custo já lançados no estoque por este item, mesmo
     * com a compra confirmada (única forma de arrumar um item não conferido
     * na hora, ex.: fator de conversão CX→UN esquecido). Delega o recálculo
     * em cadeia (custo médio, saídas/vendas posteriores) ao
     * CorrecaoEstoqueService — ver ali o alcance exato da correção.
     */
    private static function acaoCorrigirMovimentacao(): Action
    {
        return Action::make('corrigirMovimentacao')
            ->label('Corrigir quantidade/custo')
            ->icon('heroicon-o-wrench-screwdriver')
            ->color('warning')
            ->visible(fn (CompraItem $record): bool => $record->ci_produto_id
                && auth()->user()?->can('corrigir', MovimentacaoProduto::class)
                && self::localizarMovimentacao($record) !== null)
            ->modalHeading('Corrigir quantidade/custo desta entrada')
            ->modalDescription('Use quando o item não foi conferido e a quantidade ou o custo lançados no estoque estavam errados. O sistema recalcula em cadeia o custo médio e tudo que saiu do estoque depois desta entrada.')
            ->modalWidth('lg')
            ->form(function (CompraItem $record): array {
                $mov = self::localizarMovimentacao($record);
                $unidade = $record->insumo?->produto_unidade_estoque ?? '';

                return [
                    Placeholder::make('atual')
                        ->label('Registrado atualmente')
                        ->content(sprintf(
                            '%s %s a %s cada',
                            number_format((float) $mov->mov_quantidade, 3, ',', '.'),
                            $unidade,
                            CustoUnitarioFormatter::formatar((float) $mov->mov_custo_unitario),
                        )),

                    TextInput::make('quantidade_correta')
                        ->label('Quantidade correta (unidade de estoque)')
                        ->numeric()->step(0.001)->minValue(0.001)->required()
                        ->default((float) $mov->mov_quantidade)
                        ->suffix($unidade ?: null)
                        ->live(onBlur: true),

                    TextInput::make('custo_correto')
                        ->label('Custo unitário correto (unidade de estoque)')
                        ->numeric()->step(0.00000001)->minValue(0)->required()
                        ->default((float) $mov->mov_custo_unitario)
                        ->prefix('R$')
                        ->live(onBlur: true),

                    Placeholder::make('previa')
                        ->label('Prévia do impacto')
                        ->content(function (Get $get) use ($mov, $record): HtmlString {
                            $qtd = (float) ($get('quantidade_correta') ?? 0);
                            $custo = (float) ($get('custo_correto') ?? 0);

                            if ($qtd <= 0 || ! $record->insumo) {
                                return new HtmlString('Informe uma quantidade válida.');
                            }

                            $resultado = app(CorrecaoEstoqueService::class)->simular($record->insumo, $mov, $qtd, $custo);

                            return CorrecaoEstoquePreview::resumo($resultado);
                        }),

                    Textarea::make('motivo')
                        ->label('Motivo da correção')
                        ->required()
                        ->rows(2)
                        ->placeholder('Ex.: item não conferido — comprado 1 CX de 15un, lançado como 1un.'),
                ];
            })
            ->action(function (array $data, CompraItem $record): void {
                $mov = self::localizarMovimentacao($record);
                if (! $mov) {
                    Notification::make()->title('Movimentação de origem não encontrada')->danger()->send();

                    return;
                }

                try {
                    $correcao = app(CorrecaoEstoqueService::class)->aplicar(
                        $mov,
                        (float) $data['quantidade_correta'],
                        (float) $data['custo_correto'],
                        $data['motivo'],
                    );
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Não foi possível corrigir')
                        ->body(collect($e->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Correção aplicada')
                    ->body('Novo saldo do produto: '.number_format((float) $correcao->produto->produto_saldo_estoque, 3, ',', '.').' '.($record->insumo?->produto_unidade_estoque ?? '').'.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Encontra a MovimentacaoProduto de origem COMPRA gerada por este item na
     * confirmação (referência fica na Compra, não no item — CompraService
     * associa `referencia: $compra`). Quando a mesma compra tem mais de um
     * item do mesmo produto, tenta casar pela quantidade/custo ainda
     * gravados (assumindo que nenhum dos dois já foi corrigido).
     */
    private static function localizarMovimentacao(CompraItem $record): ?MovimentacaoProduto
    {
        if (! $record->ci_produto_id) {
            return null;
        }

        $candidatos = MovimentacaoProduto::where('mov_produto_id', $record->ci_produto_id)
            ->where('mov_origem', MovimentacaoOrigemEnum::COMPRA)
            ->where('mov_referencia_type', Compra::class)
            ->where('mov_referencia_id', $record->ci_compra_id)
            ->orderBy('id')
            ->get();

        if ($candidatos->count() <= 1) {
            return $candidatos->first();
        }

        return $candidatos->first(fn (MovimentacaoProduto $m): bool => abs((float) $m->mov_quantidade - $record->quantidadeEstoque()) < 0.001
            && abs((float) $m->mov_custo_unitario - $record->custoUnitarioEstoque()) < 0.00000005
        ) ?? $candidatos->first();
    }
}
