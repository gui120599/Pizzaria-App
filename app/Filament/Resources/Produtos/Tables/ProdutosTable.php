<?php

namespace App\Filament\Resources\Produtos\Tables;

use App\Enums\EstoqueModoControleEnum;
use App\Enums\MovimentacaoOrigemEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\UnidadeProdutoEnum;
use App\Filament\Components\MarcaSelect;
use App\Models\Categoria;
use App\Models\CentroCusto;
use App\Models\PlanoDespesa;
use App\Models\Produto;
use App\Services\BalancoEstoqueService;
use App\Services\EstoqueService;
use Filament\Actions\Action as ActionsAction;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\FiltersLayout;
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with('categoria'))
            ->columns([
                TextColumn::make('produto_ordem')
                    ->label('#')
                    ->badge()->color('gray')->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                ImageColumn::make('produto_foto')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->size(44)
                    ->defaultImageUrl(asset('Sem Imagem.png')),

                TextColumn::make('produto_descricao')
                    ->label('Produto')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Produto $record): ?string => $record->categoria?->categoria_nome)
                    ->wrap(),

                TextColumn::make('produto_tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ProdutoTipoEnum::tryFrom($state)?->label() ?? $state)
                    ->color(fn ($state) => match ($state) {
                        ProdutoTipoEnum::PRODUZIDO->value => 'success',
                        ProdutoTipoEnum::REVENDA->value => 'info',
                        ProdutoTipoEnum::INSUMO->value => 'warning',
                        ProdutoTipoEnum::INSUMO_PRODUZIDO->value => 'primary',
                        ProdutoTipoEnum::CONSUMO_INTERNO->value => 'danger',
                        default => 'gray',
                    })
                    ->toggleable(),

                TextColumn::make('produto_preco_venda')
                    ->label('Venda')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable()
                    ->description(fn (Produto $record): ?string => $record->produto_preco_promocional > 0
                        ? 'Promo R$ '.number_format((float) $record->produto_preco_promocional, 2, ',', '.')
                        : null),

                TextColumn::make('produto_custo_medio')
                    ->label('Custo Médio')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('produto_saldo_estoque')
                    ->label('Saldo')
                    ->numeric(decimalPlaces: 3)
                    ->suffix(fn (Produto $record): string => ' '.($record->produto_unidade_estoque ?? ''))
                    ->badge()
                    ->color(fn (Produto $record): string => ! $record->produto_controla_estoque
                        ? 'gray'
                        : ((float) $record->produto_saldo_estoque <= (float) $record->produto_quantidade_minima ? 'danger' : 'success'))
                    ->icon(fn (Produto $record): ?string => $record->produto_controla_estoque
                        && (float) $record->produto_saldo_estoque <= (float) $record->produto_quantidade_minima
                        ? 'heroicon-m-exclamation-triangle' : null)
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('valor_imobilizado')
                    ->label('Valor em Estoque')
                    ->state(fn (Produto $record): float => (float) $record->produto_saldo_estoque * (float) $record->produto_custo_medio)
                    ->money('BRL')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                ToggleColumn::make('produto_cardapio')
                    ->label('Cardápio'),

                TextColumn::make('produto_unidade_comercial')
                    ->label('Un. venda')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('produto_preco_custo')
                    ->label('Custo compra')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('produto_valor_percentual_venda')
                    ->label('Margem %')
                    ->numeric(decimalPlaces: 2)
                    ->suffix('%')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                ToggleColumn::make('produto_controla_estoque')
                    ->label('Controla Estoque')
                    ->toggleable(isToggledHiddenByDefault: true),

                ToggleColumn::make('produto_destaque_mais_vendidos')
                    ->label('Mais Vendidos')
                    ->toggleable(isToggledHiddenByDefault: true),

                ToggleColumn::make('produto_exibe_categoria')
                    ->label('Exibe Categoria')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('produto_codigo_EAN')
                    ->label('EAN')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('produto_codigo_NCM')
                    ->label('NCM')
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
                Filter::make('produto_id_busca')
                    ->label('Código')
                    ->form([
                        TextInput::make('produto_id_busca')
                            ->label('Código (ID)')
                            ->numeric()
                            ->placeholder('Digite o código do produto'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['produto_id_busca'] ?? null,
                        fn (Builder $q, $id) => $q->where('id', $id),
                    )),

                Filter::make('produto_descricao_busca')
                    ->label('Descrição')
                    ->form([
                        TextInput::make('produto_descricao_busca')
                            ->label('Descrição')
                            ->placeholder('Digite parte da descrição'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['produto_descricao_busca'] ?? null,
                        fn (Builder $q, $descricao) => $q->where('produto_descricao', 'like', "%{$descricao}%"),
                    )),

                SelectFilter::make('produto_categoria_id')
                    ->label('Categoria')
                    ->relationship('categoria', 'categoria_nome')
                    ->multiple()
                    ->searchable()
                    ->preload(),

                SelectFilter::make('categoria_pai_id')
                    ->label('Categoria Pai')
                    ->options(fn () => Categoria::whereNull('categoria_pai_id')->orderBy('categoria_nome')->pluck('categoria_nome', 'id'))
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        $paiId = $data['value'] ?? null;

                        if (! $paiId) {
                            return $query;
                        }

                        // Produto lançado direto na categoria-pai OU em alguma das filhas dela.
                        return $query->where(
                            fn (Builder $q) => $q->where('produto_categoria_id', $paiId)
                                ->orWhereHas('categoria', fn (Builder $cq) => $cq->where('categoria_pai_id', $paiId))
                        );
                    }),

                SelectFilter::make('produto_tipo')
                    ->label('Tipo')
                    ->options(
                        collect(ProdutoTipoEnum::cases())
                            ->mapWithKeys(fn ($type) => [$type->value => $type->label()])
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

                Filter::make('destaque_mais_vendidos')
                    ->label('Destaque "Mais Vendidos"')
                    ->query(fn (Builder $query): Builder => $query->where('produto_destaque_mais_vendidos', true))
                    ->toggle(),

                Filter::make('controla_estoque')
                    ->label('Controla Estoque')
                    ->query(fn (Builder $query): Builder => $query->where('produto_controla_estoque', true))
                    ->toggle(),

                SelectFilter::make('produto_modo_controle_estoque')
                    ->label('Modo de controle de estoque')
                    ->options(
                        collect(EstoqueModoControleEnum::cases())
                            ->mapWithKeys(fn ($modo) => [$modo->value => $modo->label()])
                            ->toArray()
                    ),

                Filter::make('controla_lote')
                    ->label('Controla Lote/Validade')
                    ->query(fn (Builder $query): Builder => $query->where('produto_controla_lote', true))
                    ->toggle(),

                Filter::make('controla_marca')
                    ->label('Controla Marca')
                    ->query(fn (Builder $query): Builder => $query->where('produto_controla_marca', true))
                    ->toggle(),

                Filter::make('perecivel')
                    ->label('Perecível')
                    ->query(fn (Builder $query): Builder => $query->where('produto_perecivel', true))
                    ->toggle(),

                Filter::make('abaixo_minimo')
                    ->label('Abaixo do estoque mínimo')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('produto_controla_estoque', true)
                        ->whereColumn('produto_saldo_estoque', '<=', 'produto_quantidade_minima'))
                    ->toggle(),

                TrashedFilter::make(),
            ], layout: FiltersLayout::AboveContentCollapsible)
            // Painel de filtros fora da tabela (acima dela, colapsável), em vez
            // do dropdown — mesmo padrão usado no resource de Aluguéis do
            // LocSilva2. Cada seção ocupa a linha inteira e vem recolhida.
            ->filtersFormSchema(fn (array $filters): array => [
                Section::make('Busca')
                    ->icon('heroicon-o-magnifying-glass')
                    ->collapsed(false)
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        $filters['produto_id_busca'],
                        $filters['produto_descricao_busca'],
                    ]),

                Section::make('Classificação')
                    ->icon('heroicon-o-tag')
                    ->collapsed()
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        $filters['produto_categoria_id'],
                        $filters['categoria_pai_id'],
                        $filters['produto_tipo'],
                    ]),

                Section::make('Preço e Promoção')
                    ->icon('heroicon-o-currency-dollar')
                    ->collapsed()
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        $filters['preco_venda'],
                        $filters['promocao_ativa'],
                    ]),

                Section::make('Cardápio')
                    ->icon('heroicon-o-book-open')
                    ->collapsed()
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        $filters['cardapio'],
                        $filters['destaque_mais_vendidos'],
                    ]),

                Section::make('Estoque')
                    ->icon('heroicon-o-archive-box')
                    ->collapsed()
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        $filters['controla_estoque'],
                        $filters['produto_modo_controle_estoque'],
                        $filters['controla_lote'],
                        $filters['controla_marca'],
                        $filters['perecivel'],
                        $filters['abaixo_minimo'],
                    ]),

                $filters['trashed'],
            ])
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistSearchInSession()
            ->recordActions([
                ActionsAction::make('movimentar_estoque')
                    ->label('Movimentar')
                    ->icon('heroicon-o-arrows-right-left')
                    ->tooltip('Registrar entrada/saída de estoque')
                    ->color('primary')
                    ->modalHeading(fn (Produto $record) => "Movimentar Estoque — {$record->produto_descricao}")
                    ->modalWidth('md')
                    ->form([
                        ToggleButtons::make('tipo')
                            ->label('Tipo')
                            ->options(['entrada' => 'Entrada', 'saida' => 'Saída'])
                            ->icons(['entrada' => 'heroicon-o-arrow-down-tray', 'saida' => 'heroicon-o-arrow-up-tray'])
                            ->colors(['entrada' => 'success', 'saida' => 'danger'])
                            ->inline()
                            ->grouped()
                            ->default('entrada')
                            ->live()
                            ->required(),

                        Select::make('origem')
                            ->label('Origem')
                            ->options(fn (Get $get): array => $get('tipo') === 'entrada'
                                ? [
                                    MovimentacaoOrigemEnum::COMPRA->value => MovimentacaoOrigemEnum::COMPRA->label(),
                                    MovimentacaoOrigemEnum::PRODUCAO->value => MovimentacaoOrigemEnum::PRODUCAO->label(),
                                    MovimentacaoOrigemEnum::TRANSFERENCIA->value => MovimentacaoOrigemEnum::TRANSFERENCIA->label(),
                                ]
                                : [
                                    MovimentacaoOrigemEnum::PERDA->value => MovimentacaoOrigemEnum::PERDA->label(),
                                    MovimentacaoOrigemEnum::CONSUMO_INTERNO->value => MovimentacaoOrigemEnum::CONSUMO_INTERNO->label(),
                                    MovimentacaoOrigemEnum::TRANSFERENCIA->value => MovimentacaoOrigemEnum::TRANSFERENCIA->label(),
                                ])
                            ->default(fn (Get $get): string => $get('tipo') === 'entrada'
                                ? MovimentacaoOrigemEnum::COMPRA->value
                                : MovimentacaoOrigemEnum::PERDA->value)
                            ->native(false)
                            ->required(),

                        TextInput::make('quantidade')
                            ->label('Quantidade')
                            ->numeric()
                            ->step(0.001)
                            ->minValue(0.001)
                            ->required()
                            ->suffix(fn (Produto $record): string => $record->produto_unidade_estoque ?? ''),

                        TextInput::make('custo_unitario')
                            ->label('Custo unitário')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0)
                            ->prefix('R$')
                            ->visible(fn (Get $get): bool => $get('tipo') === 'entrada')
                            ->required(fn (Get $get): bool => $get('tipo') === 'entrada')
                            ->helperText('Custo de compra; recalcula o custo médio.'),

                        TextInput::make('lote_codigo')
                            ->label('Lote')
                            ->visible(fn (Get $get, Produto $record): bool => $get('tipo') === 'entrada' && $record->produto_controla_lote),

                        MarcaSelect::make('marca_id')
                            ->visible(fn (Get $get, Produto $record): bool => $get('tipo') === 'entrada' && $record->rastreiaLote()),

                        DatePicker::make('validade')
                            ->label('Validade')
                            ->visible(fn (Get $get, Produto $record): bool => $get('tipo') === 'entrada' && $record->produto_controla_lote),

                        Select::make('centro_custo_id')
                            ->label('Centro de custo')
                            ->options(fn (): array => CentroCusto::query()->orderBy('centro_custo_nome')->pluck('centro_custo_nome', 'id')->toArray())
                            ->searchable()
                            ->placeholder('Opcional'),

                        TextInput::make('motivo')
                            ->label('Motivo / observação')
                            ->maxLength(255),
                    ])
                    ->action(function (Produto $record, array $data) {
                        $service = app(EstoqueService::class);
                        $origem = MovimentacaoOrigemEnum::from($data['origem']);
                        $opts = [
                            'centro_custo_id' => $data['centro_custo_id'] ?? null,
                            'motivo' => $data['motivo'] ?? null,
                            'lote_codigo' => $data['lote_codigo'] ?? null,
                            'marca_id' => $data['marca_id'] ?? null,
                            'validade' => $data['validade'] ?? null,
                        ];

                        if ($data['tipo'] === 'entrada') {
                            $service->registrarEntrada($record, (float) $data['quantidade'], (float) $data['custo_unitario'], $origem, $opts);
                        } else {
                            $service->registrarSaida($record, (float) $data['quantidade'], $origem, $opts);
                        }

                        $record->refresh();
                        Notification::make()
                            ->title('Estoque movimentado')
                            ->body('Novo saldo: '.number_format((float) $record->produto_saldo_estoque, 3, ',', '.').' '.($record->produto_unidade_estoque ?? ''))
                            ->success()
                            ->send();
                    }),
                ActionsAction::make('custo_pela_ficha')
                    ->label('Custo pela ficha')
                    ->icon('heroicon-o-calculator')
                    ->tooltip('Recalcular o custo médio a partir da ficha técnica')
                    ->color('info')
                    ->visible(fn (Produto $record): bool => $record->temFichaTecnica())
                    ->requiresConfirmation()
                    ->modalHeading(fn (Produto $record) => "Custo pela Ficha — {$record->produto_descricao}")
                    ->modalDescription(fn (Produto $record) => 'Custo calculado: R$ '.number_format($record->custoUnitario(), 4, ',', '.').' por '.($record->produto_unidade_estoque ?? 'unidade').'. Aplicar ao custo médio do produto?')
                    ->action(function (Produto $record) {
                        $custo = round($record->custoUnitario(), 4);
                        $record->update(['produto_custo_medio' => $custo]);

                        Notification::make()
                            ->title('Custo atualizado pela ficha')
                            ->body('Novo custo médio: R$ '.number_format($custo, 4, ',', '.'))
                            ->success()
                            ->send();
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
                                    'valor_exato' => 'Valor exato',
                                    'desconto_percentual' => 'Desconto %',
                                    'desconto_real' => 'Desconto R$',
                                    'remover' => 'Remover promoção',
                                ])
                                ->icons([
                                    'valor_exato' => 'heroicon-o-pencil',
                                    'desconto_percentual' => 'heroicon-o-percent-badge',
                                    'desconto_real' => 'heroicon-o-banknotes',
                                    'remover' => 'heroicon-o-x-circle',
                                ])
                                ->colors([
                                    'valor_exato' => 'info',
                                    'desconto_percentual' => 'warning',
                                    'desconto_real' => 'success',
                                    'remover' => 'danger',
                                ])
                                ->inline()
                                ->grouped()
                                ->default('desconto_percentual')
                                ->live()
                                ->required(),

                            TextInput::make('valor')
                                ->label(fn ($get) => match ($get('tipo')) {
                                    'valor_exato' => 'Preço promocional (R$)',
                                    'desconto_percentual' => 'Desconto (%)',
                                    default => 'Desconto (R$)',
                                })
                                ->numeric()
                                ->step(0.01)
                                ->minValue(0.01)
                                ->prefix(fn ($get) => $get('tipo') === 'desconto_percentual' ? null : 'R$')
                                ->suffix(fn ($get) => $get('tipo') === 'desconto_percentual' ? '%' : null)
                                ->visible(fn ($get) => $get('tipo') !== 'remover')
                                ->required(fn ($get) => $get('tipo') !== 'remover'),
                        ])
                        ->action(function ($records, array $data) {
                            $tipo = $data['tipo'];
                            $valor = (float) ($data['valor'] ?? 0);
                            $atualizados = 0;
                            $ignorados = 0;

                            foreach ($records as $produto) {
                                $precoVenda = (float) $produto->produto_preco_venda;

                                $novoPreco = match ($tipo) {
                                    'valor_exato' => round($valor, 2),
                                    'desconto_percentual' => round($precoVenda * (1 - $valor / 100), 2),
                                    'desconto_real' => round($precoVenda - $valor, 2),
                                    default => 0,
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
                                : "{$atualizados} produto(s) atualizados.".($ignorados > 0 ? " {$ignorados} ignorado(s) por preço inválido." : '');

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
                                $clone->produto_qtd_vendas = 0;
                                $clone->save();
                                $count++;
                            }

                            Notification::make()
                                ->title("{$count} produto(s) duplicado(s) com sucesso.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('edicao_em_lote')
                        ->label('Edição em Lote')
                        ->icon('heroicon-o-pencil-square')
                        ->color('gray')
                        ->modalHeading('Edição em Lote')
                        ->modalDescription('Marque "Alterar" só nos campos que você quer sobrescrever — os demais ficam intactos nos produtos selecionados.')
                        ->modalWidth('4xl')
                        ->form([
                            Tabs::make('edicao_em_lote')
                                ->tabs([
                                    Tab::make('Classificação')
                                        ->icon('heroicon-o-tag')
                                        ->columns(3)
                                        ->schema([
                                            Toggle::make('alterar_categoria')
                                                ->label('Alterar categoria')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            Select::make('produto_categoria_id')
                                                ->label('Categoria')
                                                ->options(fn () => Categoria::orderBy('categoria_nome')->pluck('categoria_nome', 'id'))
                                                ->searchable()
                                                ->preload()
                                                ->native(false)
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_categoria'))
                                                ->required(fn (Get $get): bool => (bool) $get('alterar_categoria'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_tipo')
                                                ->label('Alterar tipo do produto')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            Select::make('produto_tipo')
                                                ->label('Tipo do produto')
                                                ->options(collect(ProdutoTipoEnum::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->toArray())
                                                ->native(false)
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_tipo'))
                                                ->required(fn (Get $get): bool => (bool) $get('alterar_tipo'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_plano_despesa')
                                                ->label('Alterar plano de despesa')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            Select::make('produto_plano_despesa_id')
                                                ->label('Plano de despesa')
                                                ->options(fn () => PlanoDespesa::orderBy('nome')->pluck('nome', 'id'))
                                                ->searchable()
                                                ->native(false)
                                                ->placeholder('Sem classificação')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_plano_despesa'))
                                                ->columnSpan(2),
                                        ]),

                                    Tab::make('Estoque')
                                        ->icon('heroicon-o-archive-box')
                                        ->columns(3)
                                        ->schema([
                                            Toggle::make('alterar_controla_estoque')
                                                ->label('Alterar "controla estoque"')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            Toggle::make('produto_controla_estoque')
                                                ->label('Controla estoque')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_controla_estoque'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_modo_controle')
                                                ->label('Alterar modo de controle')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            Select::make('produto_modo_controle_estoque')
                                                ->label('Modo de controle')
                                                ->options(collect(EstoqueModoControleEnum::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()])->toArray())
                                                ->native(false)
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_modo_controle'))
                                                ->required(fn (Get $get): bool => (bool) $get('alterar_modo_controle'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_lista_zerado')
                                                ->label('Alterar "listar no cardápio zerado"')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            Toggle::make('produto_lista_estoque_zerado')
                                                ->label('Listar no cardápio zerado')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_lista_zerado'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_controla_lote')
                                                ->label('Alterar "controla lote"')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            Toggle::make('produto_controla_lote')
                                                ->label('Controla lote/validade')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_controla_lote'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_controla_marca')
                                                ->label('Alterar "controla marca"')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            Toggle::make('produto_controla_marca')
                                                ->label('Controla marca')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_controla_marca'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_perecivel')
                                                ->label('Alterar "perecível"')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            Toggle::make('produto_perecivel')
                                                ->label('Perecível')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_perecivel'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_unidade_estoque')
                                                ->label('Alterar unidade de estoque')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            Select::make('produto_unidade_estoque')
                                                ->label('Unidade de estoque')
                                                ->options(collect(UnidadeProdutoEnum::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->toArray())
                                                ->searchable()
                                                ->native(false)
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_unidade_estoque'))
                                                ->required(fn (Get $get): bool => (bool) $get('alterar_unidade_estoque'))
                                                ->columnSpan(2),
                                        ]),

                                    Tab::make('Cardápio')
                                        ->icon('heroicon-o-bars-3-bottom-left')
                                        ->columns(3)
                                        ->schema([
                                            Toggle::make('alterar_cardapio')
                                                ->label('Alterar "mostrar no cardápio"')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            Toggle::make('produto_cardapio')
                                                ->label('Mostrar no cardápio')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_cardapio'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_destaque')
                                                ->label('Alterar destaque "Mais Vendidos"')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            Toggle::make('produto_destaque_mais_vendidos')
                                                ->label('Destaque "Mais Vendidos"')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_destaque'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_exibe_categoria')
                                                ->label('Alterar "incluir categoria no nome"')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            Toggle::make('produto_exibe_categoria')
                                                ->label('Incluir a categoria no nome')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_exibe_categoria'))
                                                ->columnSpan(2),
                                        ]),

                                    Tab::make('Fiscal')
                                        ->icon('heroicon-o-document-text')
                                        ->columns(3)
                                        ->schema([
                                            Toggle::make('alterar_ncm')
                                                ->label('Alterar NCM')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            TextInput::make('produto_codigo_NCM')
                                                ->label('NCM')
                                                ->maxLength(8)
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_ncm'))
                                                ->required(fn (Get $get): bool => (bool) $get('alterar_ncm'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_cest')
                                                ->label('Alterar CEST')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            TextInput::make('produto_codigo_CEST')
                                                ->label('CEST')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_cest'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_cfop')
                                                ->label('Alterar CFOP')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            TextInput::make('produto_CFOP')
                                                ->label('CFOP')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_cfop'))
                                                ->required(fn (Get $get): bool => (bool) $get('alterar_cfop'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_csosn')
                                                ->label('Alterar CSOSN')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            TextInput::make('produto_CSOSN')
                                                ->label('CSOSN')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_csosn'))
                                                ->required(fn (Get $get): bool => (bool) $get('alterar_csosn'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_trib_icms')
                                                ->label('Alterar tributação ICMS')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            TextInput::make('produto_cod_tributacao_icms')
                                                ->label('Tributação ICMS')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_trib_icms'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_origem_mercadoria')
                                                ->label('Alterar origem da mercadoria')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            TextInput::make('produto_cod_origem_mercadoria')
                                                ->label('Origem')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_origem_mercadoria'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_icms')
                                                ->label('Alterar % ICMS')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            TextInput::make('produto_valor_percentual_icms')
                                                ->label('% ICMS')
                                                ->numeric()
                                                ->suffix('%')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_icms'))
                                                ->required(fn (Get $get): bool => (bool) $get('alterar_icms'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_reducao_icms')
                                                ->label('Alterar % Redução ICMS')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            TextInput::make('produto_valor_percentual_reducao_icms')
                                                ->label('% Redução ICMS')
                                                ->numeric()
                                                ->suffix('%')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_reducao_icms'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_pis')
                                                ->label('Alterar % PIS')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            TextInput::make('produto_valor_percentual_pis')
                                                ->label('% PIS')
                                                ->numeric()
                                                ->suffix('%')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_pis'))
                                                ->required(fn (Get $get): bool => (bool) $get('alterar_pis'))
                                                ->columnSpan(2),

                                            Toggle::make('alterar_cofins')
                                                ->label('Alterar % COFINS')
                                                ->live()
                                                ->inline(false)
                                                ->columnSpan(1),
                                            TextInput::make('produto_valor_percentual_cofins')
                                                ->label('% COFINS')
                                                ->numeric()
                                                ->suffix('%')
                                                ->visible(fn (Get $get): bool => (bool) $get('alterar_cofins'))
                                                ->required(fn (Get $get): bool => (bool) $get('alterar_cofins'))
                                                ->columnSpan(2),
                                        ]),
                                ]),
                        ])
                        ->action(function ($records, array $data) {
                            // Mapa toggle "alterar_X" → coluna real. Só entra no update o
                            // campo cujo toggle veio marcado — os demais ficam intocados,
                            // mesmo que o form tenha algum valor default renderizado.
                            $campos = [
                                'alterar_categoria' => 'produto_categoria_id',
                                'alterar_tipo' => 'produto_tipo',
                                'alterar_plano_despesa' => 'produto_plano_despesa_id',
                                'alterar_controla_estoque' => 'produto_controla_estoque',
                                'alterar_modo_controle' => 'produto_modo_controle_estoque',
                                'alterar_lista_zerado' => 'produto_lista_estoque_zerado',
                                'alterar_controla_lote' => 'produto_controla_lote',
                                'alterar_controla_marca' => 'produto_controla_marca',
                                'alterar_perecivel' => 'produto_perecivel',
                                'alterar_unidade_estoque' => 'produto_unidade_estoque',
                                'alterar_cardapio' => 'produto_cardapio',
                                'alterar_destaque' => 'produto_destaque_mais_vendidos',
                                'alterar_exibe_categoria' => 'produto_exibe_categoria',
                                'alterar_ncm' => 'produto_codigo_NCM',
                                'alterar_cest' => 'produto_codigo_CEST',
                                'alterar_cfop' => 'produto_CFOP',
                                'alterar_csosn' => 'produto_CSOSN',
                                'alterar_trib_icms' => 'produto_cod_tributacao_icms',
                                'alterar_origem_mercadoria' => 'produto_cod_origem_mercadoria',
                                'alterar_icms' => 'produto_valor_percentual_icms',
                                'alterar_reducao_icms' => 'produto_valor_percentual_reducao_icms',
                                'alterar_pis' => 'produto_valor_percentual_pis',
                                'alterar_cofins' => 'produto_valor_percentual_cofins',
                            ];

                            $update = [];
                            foreach ($campos as $toggle => $campo) {
                                if (! empty($data[$toggle])) {
                                    $update[$campo] = $data[$campo] ?? null;
                                }
                            }

                            if ($update === []) {
                                Notification::make()
                                    ->title('Nada para alterar')
                                    ->body('Marque ao menos um campo antes de aplicar.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $count = 0;
                            foreach ($records as $produto) {
                                $produto->update($update);
                                $count++;
                            }

                            Notification::make()
                                ->title('Produtos atualizados')
                                ->body("{$count} produto(s) atualizado(s) em ".count($update).' campo(s).')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('balanco_em_lote')
                        ->label('Realizar Balanço')
                        ->icon('heroicon-o-scale')
                        ->color('warning')
                        ->modalHeading('Balanço físico em lote')
                        ->modalDescription('Informe a quantidade física contada de cada produto selecionado. Produtos que não controlam estoque ficam de fora.')
                        ->modalWidth('4xl')
                        ->schema(function ($records): array {
                            $itens = collect($records)
                                ->filter(fn (Produto $produto): bool => (bool) $produto->produto_controla_estoque)
                                ->map(fn (Produto $produto): array => [
                                    'produto_id' => $produto->id,
                                    'produto_descricao' => $produto->produto_descricao,
                                    'saldo_atual' => (float) $produto->produto_saldo_estoque,
                                    'quantidade_contada' => (float) $produto->produto_saldo_estoque,
                                    'controla_lote' => (bool) $produto->produto_controla_lote,
                                    'rastreia_lote' => $produto->rastreiaLote(),
                                    'lote_codigo' => null,
                                    'marca_id' => null,
                                    'validade' => null,
                                ])
                                ->values()
                                ->toArray();

                            if ($itens === []) {
                                return [
                                    TextInput::make('aviso_vazio')
                                        ->hiddenLabel()
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->default('Nenhum produto selecionado controla estoque.'),
                                ];
                            }

                            return [
                                Repeater::make('itens')
                                    ->hiddenLabel()
                                    ->table([
                                        TableColumn::make('Produto'),
                                        TableColumn::make('Saldo no sistema'),
                                        TableColumn::make('Quantidade contada'),
                                        TableColumn::make('Lote (se houver sobra)'),
                                        TableColumn::make('Marca'),
                                        TableColumn::make('Validade'),
                                    ])
                                    ->schema([
                                        Hidden::make('produto_id'),
                                        Hidden::make('controla_lote'),
                                        Hidden::make('rastreia_lote'),
                                        TextInput::make('produto_descricao')
                                            ->hiddenLabel()
                                            ->disabled()
                                            ->dehydrated(false),
                                        TextInput::make('saldo_atual')
                                            ->hiddenLabel()
                                            ->numeric()
                                            ->disabled()
                                            ->dehydrated(false),
                                        TextInput::make('quantidade_contada')
                                            ->hiddenLabel()
                                            ->numeric()
                                            ->step(0.001)
                                            ->minValue(0)
                                            ->required(),
                                        TextInput::make('lote_codigo')
                                            ->hiddenLabel()
                                            ->visible(fn (Get $get): bool => (bool) $get('controla_lote')),
                                        MarcaSelect::make('marca_id')
                                            ->hiddenLabel()
                                            ->visible(fn (Get $get): bool => (bool) $get('rastreia_lote')),
                                        DatePicker::make('validade')
                                            ->hiddenLabel()
                                            ->visible(fn (Get $get): bool => (bool) $get('controla_lote')),
                                    ])
                                    ->default($itens)
                                    ->addable(false)
                                    ->deletable(false)
                                    ->reorderable(false)
                                    ->columnSpanFull(),
                            ];
                        })
                        ->action(function (array $data, $records) {
                            $service = app(BalancoEstoqueService::class);
                            $records = collect($records);
                            $ajustados = 0;
                            $semDiferenca = 0;

                            foreach ($data['itens'] ?? [] as $item) {
                                /** @var Produto|null $produto */
                                $produto = $records->firstWhere('id', (int) $item['produto_id']);

                                if (! $produto) {
                                    continue;
                                }

                                $balanco = $service->realizarBalanco(
                                    produto: $produto,
                                    quantidadeFisica: (float) $item['quantidade_contada'],
                                    loteCodigo: $item['lote_codigo'] ?? null,
                                    marcaId: ! empty($item['marca_id']) ? (int) $item['marca_id'] : null,
                                    validade: $item['validade'] ?? null,
                                );

                                $balanco ? $ajustados++ : $semDiferenca++;
                            }

                            Notification::make()
                                ->title('Balanço em lote concluído')
                                ->body("{$ajustados} produto(s) ajustado(s), {$semDiferenca} sem diferença.")
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
