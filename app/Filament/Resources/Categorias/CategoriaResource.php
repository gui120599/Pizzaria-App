<?php

namespace App\Filament\Resources\Categorias;

use App\Filament\Resources\Categorias\Pages\ManageCategorias;
use App\Models\Categoria;
use App\Models\ProdutoPrecoHistorico;
use BackedEnum;
use Filament\Actions\Action as ActionsAction;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section as SchemaSection;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Leandrocfe\FilamentPtbrFormFields\Money;
use UnitEnum;

class CategoriaResource extends Resource
{
    protected static ?string $model = Categoria::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Categorias';

    protected static UnitEnum|string|null $navigationGroup = 'Cardápio';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'categoria_nome';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                SchemaSection::make('Informações Básicas')
                    ->description('Dados principais da categoria')
                    ->icon('heroicon-o-bookmark')
                    ->schema([
                        TextInput::make('categoria_nome')
                            ->label('Nome da Categoria')
                            ->placeholder('Ex: Pizzas, Bebidas, Sobremesas')
                            ->required()
                            ->maxLength(100)
                            ->helperText('Identifica a categoria de forma clara'),

                        Select::make('categoria_pai_id')
                            ->label('Categoria Pai')
                            ->options(fn (?Categoria $record) => Categoria::query()
                                ->when($record, fn ($q) => $q->whereNotIn('id', [$record->id, ...$record->idsDescendentes()]))
                                ->orderBy('categoria_nome')
                                ->pluck('categoria_nome', 'id'))
                            ->searchable()
                            ->native(false)
                            ->placeholder('Nenhuma (categoria de topo)')
                            ->helperText('Agrupa esta categoria sob outra — usado no filtro de produtos por categoria pai.'),

                        TextInput::make('categoria_preposicao_padrao')
                            ->label('Preposição Padrão')
                            ->placeholder('Ex: DE, DO, DA, COM')
                            ->maxLength(20)
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state ? mb_strtoupper(trim($state)) : null)
                            ->helperText('Aplicada ao marcar "Exibe Categoria" nos produtos. Ex.: PASTEL DE FRANGO'),

                        TextInput::make('categoria_ordem')
                            ->label('Ordem de Exibição')
                            ->numeric()
                            ->integer()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Menor número aparece primeiro. Categorias com mesma ordem ficam em ordem alfabética'),

                        Toggle::make('categoria_cardapio')
                            ->label('Mostrar no Cardápio')
                            ->required()
                            ->inline()
                            ->helperText('Categorias visíveis aos clientes'),

                        Toggle::make('categoria_permite_sabores')
                            ->label('Permite Múltiplos Sabores')
                            ->inline()
                            ->live()
                            ->helperText('Ative para categorias como pizzas (meia a meia, terços)'),

                        \Filament\Forms\Components\Select::make('categoria_max_sabores')
                            ->label('Máximo de Sabores')
                            ->options([2 => 'Até 2 (Meia a Meia)', 3 => 'Até 3 (Terços)'])
                            ->default(2)
                            ->visible(fn ($get) => (bool) $get('categoria_permite_sabores'))
                            ->required(fn ($get) => (bool) $get('categoria_permite_sabores'))
                            ->helperText('Quantos sabores o cliente pode combinar'),
                    ]),

                SchemaSection::make('Estatísticas')
                    ->description('Informações sobre produtos e ajustes')
                    ->icon('heroicon-o-chart-bar')
                    ->disabled()
                    ->collapsible()
                    ->schema([
                        Placeholder::make('total_produtos')
                            ->label('Total de Produtos')
                            ->content(fn (?Categoria $record) => $record?->produtos()->count() ?? 0),

                        Placeholder::make('ultimo_ajuste_info')
                            ->label('Último Ajuste de Preço')
                            ->content(fn (?Categoria $record) => $record
                                ? (self::resumoUltimoAjusteTabela($record) ?? 'Nenhum ajuste')
                                : 'N/A'
                            ),

                        Placeholder::make('ajustes_total')
                            ->label('Total de Ajustes')
                            ->content(fn (?Categoria $record) => $record?->historicosPrecos()->count() ?? 0),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('categoria_nome')
            ->reorderable('categoria_ordem')
            ->defaultSort('categoria_ordem')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['ultimoHistoricoPreco', 'pai'])
                ->withCount('produtos')
            )
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->width('60px'),

                TextColumn::make('categoria_ordem')
                    ->label('Ordem')
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('gray')
                    ->width('70px'),

                TextColumn::make('categoria_nome')
                    ->label('Nome da Categoria')
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('pai.categoria_nome')
                    ->label('Categoria Pai')
                    ->placeholder('—')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                BadgeColumn::make('produtos_count')
                    ->label('Produtos')
                    ->getStateUsing(fn (Categoria $record) => $record->produtos_count ?? 0)
                    ->color(fn ($state) => match (true) {
                        $state == 0 => 'warning',
                        $state < 5 => 'info',
                        $state < 10 => 'success',
                        default => 'primary',
                    })
                    ->icon('heroicon-o-squares-2x2')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                ToggleColumn::make('categoria_cardapio')
                    ->label('Cardápio')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('ultimo_ajuste_preco')
                    ->label('Último Ajuste')
                    ->state(fn (Categoria $record): string => self::resumoUltimoAjusteTabela($record))
                    ->description(fn (Categoria $record): ?string => self::descricaoUltimoAjusteTabela($record))
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->wrap()
                    ->sortable(query: fn (Builder $query, $direction) => $query->orderBy(
                        ProdutoPrecoHistorico::selectRaw('max(created_at)')
                            ->whereColumn('categoria_id', 'categorias.id'),
                        $direction
                    )
                    ),

                TextColumn::make('ajustes_count')
                    ->label('# Ajustes')
                    ->getStateUsing(fn (Categoria $record) => $record->historicosPrecos()->count())
                    ->badge()
                    ->color('secondary')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('historicosPrecos_count')
                    ->label('Histórico')
                    ->counts('historicosPrecos')
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
                TernaryFilter::make('categoria_cardapio')
                    ->label('No Cardápio')
                    ->trueLabel('Sim - Mostrar no cardápio')
                    ->falseLabel('Não - Oculto do cardápio'),

                TernaryFilter::make('com_produtos')
                    ->label('Com Produtos')
                    ->trueLabel('Sim - Com produtos')
                    ->falseLabel('Não - Sem produtos')
                    ->query(function (Builder $query, $value) {
                        return match ($value) {
                            true => $query->has('produtos'),
                            false => $query->doesntHave('produtos'),
                            default => $query,
                        };
                    }),

                Filter::make('ajustadas_hoje')
                    ->label('Ajustadas Hoje')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'historicosPrecos',
                        fn (Builder $historicoQuery): Builder => $historicoQuery->whereDate('created_at', now()->toDateString())
                    ))
                    ->toggle(),

                Filter::make('ajustadas_semana')
                    ->label('Ajustadas essa Semana')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'historicosPrecos',
                        fn (Builder $historicoQuery): Builder => $historicoQuery->whereBetween('created_at', [
                            now()->startOfWeek(),
                            now()->endOfWeek(),
                        ])
                    ))
                    ->toggle(),

                Filter::make('ajustadas_mes')
                    ->label('Ajustadas esse Mês')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'historicosPrecos',
                        fn (Builder $historicoQuery): Builder => $historicoQuery->whereYear('created_at', now()->year)
                            ->whereMonth('created_at', now()->month)
                    ))
                    ->toggle(),

                Filter::make('sem_historico')
                    ->label('Sem Histórico de Ajustes')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('historicosPrecos'))
                    ->toggle(),

                Filter::make('com_ajustes_pendentes')
                    ->label('Com Ajustes Reversíveis')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'historicosPrecos',
                        fn (Builder $historicoQuery): Builder => $historicoQuery->whereNull('restaurado_em')
                    ))
                    ->toggle(),

                SelectFilter::make('quantidade_produtos')
                    ->label('Quantidade de Produtos')
                    ->options([
                        'vazio' => 'Sem produtos',
                        'poucos' => 'Poucos (1-5)',
                        'alguns' => 'Alguns (6-10)',
                        'muitos' => 'Muitos (11+)',
                    ])
                    ->query(function (Builder $query, $value) {
                        return match ($value) {
                            'vazio' => $query->doesnthave('produtos'),
                            'poucos' => $query->has('produtos', '>=', 1)->has('produtos', '<=', 5),
                            'alguns' => $query->has('produtos', '>=', 6)->has('produtos', '<=', 10),
                            'muitos' => $query->has('produtos', '>=', 11),
                            default => $query,
                        };
                    }),

                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionsAction::make('ajustar_precos_venda')
                    ->icon('heroicon-o-currency-dollar')
                    ->tooltip('Ajustar preços de venda')
                    ->modalHeading('Aplicar reajuste na categoria')
                    ->modalDescription('Reajuste coletivo por tipo de operação e unidade de cálculo.')
                    ->modalWidth('lg')
                    ->form([
                        Placeholder::make('ultimo_ajuste_em_massa')
                            ->label('Última atualização por esta função')
                            ->content(fn (Categoria $record): string => self::resumoUltimaExecucao($record, 'ajustar_precos_venda')),

                        ToggleButtons::make('ajuste_tipo')
                            ->label('Operação')
                            ->options([
                                'aumento' => 'Aumento',
                                'reducao' => 'Redução',
                            ])
                            ->icons([
                                'aumento' => 'heroicon-o-arrow-trending-up',
                                'reducao' => 'heroicon-o-arrow-trending-down',
                            ])
                            ->colors([
                                'aumento' => 'success',
                                'reducao' => 'danger',
                            ])
                            ->inline()
                            ->grouped()
                            ->default('aumento')
                            ->live()
                            ->required()
                            ->helperText('Escolha se o reajuste vai aumentar ou reduzir os preços'),

                        ToggleButtons::make('ajuste_unidade')
                            ->label('Unidade do reajuste')
                            ->options([
                                'real' => 'Reais (R$)',
                                'porcentagem' => 'Porcentagem (%)',
                            ])
                            ->icons([
                                'real' => 'heroicon-o-banknotes',
                                'porcentagem' => 'heroicon-o-percent-badge',
                            ])
                            ->colors([
                                'real' => 'primary',
                                'porcentagem' => 'warning',
                            ])
                            ->inline()
                            ->grouped()
                            ->default('real')
                            ->live()
                            ->required()
                            ->helperText('Escolha entre valor fixo ou percentual'),

                        Money::make('ajuste_real')
                            ->label('Valor em R$')
                            ->placeholder('Ex: 2,00')
                            ->visible(fn ($get) => $get('ajuste_unidade') === 'real')
                            ->required(fn ($get) => $get('ajuste_unidade') === 'real')
                            ->helperText('Valor absoluto aplicado por produto'),

                        TextInput::make('ajuste_percentual')
                            ->label('Percentual (%)')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('%')
                            ->placeholder('Ex: 5')
                            ->visible(fn ($get) => $get('ajuste_unidade') === 'porcentagem')
                            ->required(fn ($get) => $get('ajuste_unidade') === 'porcentagem')
                            ->helperText('Percentual aplicado com base no preço atual ou margem'),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Categoria $record, array $data) {
                        $tipo = $data['ajuste_tipo'] ?? 'aumento';
                        $unidade = $data['ajuste_unidade'] ?? 'real';
                        $ajusteBase = $unidade === 'porcentagem'
                            ? (float) ($data['ajuste_percentual'] ?? 0)
                            : (float) ($data['ajuste_real'] ?? 0);
                        $loteUuid = (string) Str::uuid();
                        $usuarioId = auth()->id();

                        if ($ajusteBase <= 0) {
                            Notification::make()
                                ->title('Ajuste inválido')
                                ->body('Informe um valor maior que zero para aplicar o reajuste.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $ajuste = $tipo === 'reducao' ? -abs($ajusteBase) : abs($ajusteBase);
                        $atualizados = 0;
                        $fallbackSemCusto = 0;

                        $record->produtos()
                            ->chunkById(100, function ($produtos) use ($record, $ajuste, $unidade, $loteUuid, $usuarioId, &$atualizados, &$fallbackSemCusto) {
                                foreach ($produtos as $produto) {
                                    $custo = (float) ($produto->produto_preco_custo ?? 0);
                                    $margem = (float) ($produto->produto_valor_percentual_venda ?? 0);
                                    $precoVendaAtual = (float) ($produto->produto_preco_venda ?? 0);
                                    $possuiCusto = $custo > 0;
                                    $novoCusto = $custo;
                                    $novoMargem = $margem;

                                    if ($unidade === 'real') {
                                        if ($possuiCusto) {
                                            $novoCusto = max(0, $custo + $ajuste);
                                            $novoPrecoVenda = round($novoCusto * (1 + ($novoMargem / 100)), 2);
                                        } else {
                                            $novoPrecoVenda = round($precoVendaAtual + $ajuste, 2);
                                        }
                                    } else {
                                        if ($possuiCusto) {
                                            $novoMargem = max(0, $margem + $ajuste);
                                            $novoPrecoVenda = round($novoCusto * (1 + ($novoMargem / 100)), 2);
                                        } else {
                                            $novoPrecoVenda = round($precoVendaAtual * (1 + ($ajuste / 100)), 2);
                                        }
                                    }

                                    if ((! $possuiCusto) && ($novoPrecoVenda <= 0)) {
                                        $fallbackSemCusto++;
                                        $novoPrecoVenda = $precoVendaAtual > 0 ? $precoVendaAtual : 0.01;
                                    }

                                    self::registrarHistoricoProduto(
                                        categoriaId: $record->id,
                                        produto: $produto,
                                        acao: 'ajustar_precos_venda',
                                        loteUuid: $loteUuid,
                                        usuarioId: $usuarioId,
                                        novoCusto: round($novoCusto, 2),
                                        novoPercentual: round($novoMargem, 2),
                                        novoVenda: round($novoPrecoVenda, 2),
                                    );

                                    $produto->forceFill([
                                        'produto_preco_custo' => round($novoCusto, 2),
                                        'produto_valor_percentual_venda' => round($novoMargem, 2),
                                        'produto_preco_venda' => round($novoPrecoVenda, 2),
                                    ])->saveQuietly();

                                    $atualizados++;
                                }
                            });

                        $operacao = $tipo === 'reducao' ? 'reduzidos' : 'aumentados';
                        $valorFormatado = $unidade === 'porcentagem'
                            ? number_format(abs($ajuste), 2, ',', '.').'%'
                            : 'R$ '.number_format(abs($ajuste), 2, ',', '.');
                        $fallbackMensagem = $fallbackSemCusto > 0
                            ? " | {$fallbackSemCusto} produtos sem custo mantiveram preço de venda válido."
                            : '';

                        Notification::make()
                            ->title('Preços atualizados')
                            ->body("{$atualizados} produtos {$operacao} em {$valorFormatado}{$fallbackMensagem}")
                            ->success()
                            ->send();
                    }),
                ActionsAction::make('definir_precos_exatos')
                    ->icon('heroicon-o-pencil-square')
                    ->tooltip('Definir preços exatos')
                    ->color('warning')
                    ->modalHeading('Definir preços exatos da categoria')
                    ->modalDescription('Aplica os mesmos valores de custo, margem e venda para todos os produtos da categoria.')
                    ->modalWidth('lg')
                    ->form([
                        Placeholder::make('ultima_definicao_exata')
                            ->label('Última atualização por esta função')
                            ->content(fn (Categoria $record): string => self::resumoUltimaExecucao($record, 'definir_precos_exatos')),

                        Money::make('preco_venda_exato')
                            ->label('Preço de venda (R$)')
                            ->placeholder('Ex: 18,90')
                            ->required(fn ($get) => self::parseDecimal($get('preco_custo_exato')) == 0.0)
                            ->disabled(fn ($get) => self::parseDecimal($get('preco_custo_exato')) > 0)
                            ->helperText(fn ($get) => self::parseDecimal($get('preco_custo_exato')) > 0
                                ? 'Com custo maior que zero, o preço de venda será calculado automaticamente por custo + percentual.'
                                : 'Com custo igual a zero, informe manualmente o preço de venda (percentual deve ser 100%).'),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Categoria $record, array $data) {

                        $vendaInformada = round(self::parseDecimal($data['preco_venda_exato'] ?? 0), 2);
                        $loteUuid = (string) Str::uuid();
                        $usuarioId = auth()->id();
                        $vendaFinal = $vendaInformada;
                        $atualizados = 0;

                        $record->produtos()
                            ->chunkById(100, function ($produtos) use ($record, $loteUuid, $usuarioId, $vendaFinal, &$atualizados) {
                                foreach ($produtos as $produto) {
                                    self::registrarHistoricoProduto(
                                        categoriaId: $record->id,
                                        produto: $produto,
                                        acao: 'definir_precos_exatos',
                                        loteUuid: $loteUuid,
                                        usuarioId: $usuarioId,
                                        novoCusto: $vendaFinal,
                                        novoPercentual: 0,
                                        novoVenda: $vendaFinal,
                                    );

                                    $produto->forceFill([
                                        'produto_preco_custo' => $vendaFinal,
                                        'produto_valor_percentual_venda' => 0,
                                        'produto_preco_venda' => $vendaFinal,
                                    ])->saveQuietly();

                                    $atualizados++;
                                }
                            });

                        Notification::make()
                            ->title('Preços definidos com sucesso')
                            ->body(
                                "{$atualizados} produtos atualizados para Custo R$ ".' | Venda R$ '.number_format($vendaFinal, 2, ',', '.')
                            )
                            ->success()
                            ->send();
                    }),
                ActionsAction::make('desfazer_ultimo_reajuste')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->tooltip('Desfazer último reajuste')
                    ->color('gray')
                    ->modalHeading('Desfazer último reajuste da categoria')
                    ->modalDescription('Restaura o último lote de preços alterados por ações em massa desta categoria.')
                    ->requiresConfirmation()
                    ->action(function (Categoria $record) {
                        $loteUuid = ProdutoPrecoHistorico::query()
                            ->where('categoria_id', $record->id)
                            ->whereNull('restaurado_em')
                            ->latest('id')
                            ->value('lote_uuid');

                        if (! $loteUuid) {
                            Notification::make()
                                ->title('Nada para desfazer')
                                ->body('Não há histórico pendente para restauração nesta categoria.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $historicos = ProdutoPrecoHistorico::query()
                            ->where('categoria_id', $record->id)
                            ->where('lote_uuid', $loteUuid)
                            ->whereNull('restaurado_em')
                            ->orderBy('id')
                            ->get();

                        if ($historicos->isEmpty()) {
                            Notification::make()
                                ->title('Nada para desfazer')
                                ->body('O último lote já foi restaurado anteriormente.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $produtos = $record->produtos()
                            ->whereIn('id', $historicos->pluck('produto_id')->all())
                            ->get()
                            ->keyBy('id');

                        $atualizados = 0;

                        foreach ($historicos as $historico) {
                            $produto = $produtos->get($historico->produto_id);

                            if (! $produto) {
                                continue;
                            }

                            $produto->forceFill([
                                'produto_preco_custo' => $historico->valor_antigo_custo,
                                'produto_valor_percentual_venda' => $historico->valor_antigo_percentual,
                                'produto_preco_venda' => $historico->valor_antigo_venda,
                            ])->saveQuietly();

                            $atualizados++;
                        }

                        ProdutoPrecoHistorico::query()
                            ->where('categoria_id', $record->id)
                            ->where('lote_uuid', $loteUuid)
                            ->whereNull('restaurado_em')
                            ->update([
                                'restaurado_em' => now(),
                                'restaurado_por' => auth()->id(),
                            ]);

                        Notification::make()
                            ->title('Reajuste desfeito')
                            ->body("{$atualizados} produtos restaurados para os valores antigos.")
                            ->success()
                            ->send();
                    }),
                ActionsAction::make('ajustar_preco_promocional')
                    ->icon('heroicon-o-tag')
                    ->tooltip('Ajustar preço promocional')
                    ->color('warning')
                    ->modalHeading(fn (Categoria $record) => "Preço Promocional — {$record->categoria_nome}")
                    ->modalDescription('Aplica ou remove o preço promocional em todos os produtos desta categoria.')
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
                    ->requiresConfirmation()
                    ->action(function (Categoria $record, array $data) {
                        $tipo = $data['tipo'];
                        $valor = (float) ($data['valor'] ?? 0);
                        $atualizados = 0;
                        $ignorados = 0;

                        $record->produtos()->chunkById(100, function ($produtos) use ($tipo, $valor, &$atualizados, &$ignorados) {
                            foreach ($produtos as $produto) {
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
                        });

                        $msg = $tipo === 'remover'
                            ? "{$atualizados} produto(s) com promoção removida."
                            : "{$atualizados} produto(s) atualizados.".($ignorados > 0 ? " {$ignorados} ignorado(s) por preço inválido." : '');

                        Notification::make()->title('Preço promocional atualizado')->body($msg)->success()->send();
                    }),
                ActionsAction::make('toggle_cardapio')
                    ->icon(fn (Categoria $record): string => $record->categoria_cardapio ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->tooltip(fn (Categoria $record): string => $record->categoria_cardapio ? 'Remover do Cardápio' : 'Adicionar ao Cardápio')
                    ->color(fn (Categoria $record): string => $record->categoria_cardapio ? 'warning' : 'success')
                    ->action(function (Categoria $record) {
                        $record->update(['categoria_cardapio' => ! $record->categoria_cardapio]);

                        $acao = $record->categoria_cardapio ? 'adicionada ao' : 'removida do';
                        $emoji = $record->categoria_cardapio ? '👁️' : '🚫';

                        Notification::make()
                            ->title('Status do Cardápio Alterado')
                            ->body("{$emoji} Categoria {$acao} cardápio com sucesso.")
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation(fn (Categoria $record): bool => $record->categoria_cardapio),
                ActionsAction::make('ver_produtos')
                    ->icon('heroicon-o-list-bullet')
                    ->tooltip('Ver Produtos')
                    ->color('info')
                    ->modalHeading(fn (Categoria $record): string => "Produtos - {$record->categoria_nome}")
                    ->modalContent(fn (Categoria $record) => new HtmlString(self::gerarListaProdutos($record)))
                    ->modalWidth('2xl'),
                ActionsAction::make('ver_historico')
                    ->icon('heroicon-o-clock')
                    ->tooltip('Ver Histórico')
                    ->color('gray')
                    ->modalHeading(fn (Categoria $record): string => "Histórico de Ajustes - {$record->categoria_nome}")
                    ->modalContent(fn (Categoria $record) => new HtmlString(self::gerarHistoricoAjustes($record)))
                    ->modalWidth('3xl'),
                EditAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('ajustar_preco_promocional')
                        ->label('Ajustar Preço Promocional')
                        ->icon('heroicon-o-tag')
                        ->color('warning')
                        ->modalHeading('Ajustar Preço Promocional')
                        ->modalDescription('Aplica a operação em todos os produtos das categorias selecionadas.')
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

                            foreach ($records as $categoria) {
                                $categoria->produtos()->chunkById(100, function ($produtos) use ($tipo, $valor, &$atualizados, &$ignorados) {
                                    foreach ($produtos as $produto) {
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
                                });
                            }

                            $msg = $tipo === 'remover'
                                ? "{$atualizados} produto(s) com promoção removida."
                                : "{$atualizados} produto(s) atualizados.".($ignorados > 0 ? " {$ignorados} ignorado(s) por preço inválido." : '');

                            Notification::make()->title('Preço promocional atualizado')->body($msg)->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('definir_categoria_pai')
                        ->label('Definir Categoria Pai')
                        ->icon('heroicon-o-squares-2x2')
                        ->color('gray')
                        ->modalHeading('Definir Categoria Pai')
                        ->modalDescription('Define a categoria pai das categorias selecionadas. Deixe em branco para tornar as categorias selecionadas categorias de topo.')
                        ->modalWidth('md')
                        ->form([
                            Select::make('categoria_pai_id')
                                ->label('Categoria Pai')
                                ->options(fn () => Categoria::orderBy('categoria_nome')->pluck('categoria_nome', 'id'))
                                ->searchable()
                                ->native(false)
                                ->placeholder('Nenhuma (categoria de topo)'),
                        ])
                        ->action(function ($records, array $data) {
                            $paiId = $data['categoria_pai_id'] ?? null;
                            $atualizados = 0;
                            $ignorados = 0;

                            foreach ($records as $categoria) {
                                // Impede ciclo: a categoria escolhida como pai não pode ser
                                // a própria categoria sendo atualizada nem uma descendente dela.
                                if ($paiId && ((int) $paiId === $categoria->id || in_array((int) $paiId, $categoria->idsDescendentes(), true))) {
                                    $ignorados++;

                                    continue;
                                }

                                $categoria->update(['categoria_pai_id' => $paiId]);
                                $atualizados++;
                            }

                            $msg = $paiId
                                ? "{$atualizados} categoria(s) atualizada(s)."
                                : "{$atualizados} categoria(s) tornada(s) categoria de topo.";
                            $msg .= $ignorados > 0 ? " {$ignorados} ignorada(s) por criar ciclo na hierarquia." : '';

                            Notification::make()->title('Categoria pai atualizada')->body($msg)->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCategorias::route('/'),
        ];
    }

    private static function registrarHistoricoProduto(
        int $categoriaId,
        mixed $produto,
        string $acao,
        string $loteUuid,
        ?int $usuarioId,
        float $novoCusto,
        float $novoPercentual,
        float $novoVenda,
    ): void {
        ProdutoPrecoHistorico::create([
            'lote_uuid' => $loteUuid,
            'acao' => $acao,
            'categoria_id' => $categoriaId,
            'produto_id' => $produto->id,
            'usuario_id' => $usuarioId,
            'valor_antigo_custo' => $produto->produto_preco_custo !== null
                ? round((float) $produto->produto_preco_custo, 2)
                : null,
            'valor_antigo_percentual' => $produto->produto_valor_percentual_venda !== null
                ? round((float) $produto->produto_valor_percentual_venda, 2)
                : null,
            'valor_antigo_venda' => $produto->produto_preco_venda !== null
                ? round((float) $produto->produto_preco_venda, 2)
                : null,
            'valor_novo_custo' => round($novoCusto, 2),
            'valor_novo_percentual' => round($novoPercentual, 2),
            'valor_novo_venda' => round($novoVenda, 2),
        ]);
    }

    private static function resumoUltimoAjusteTabela(Categoria $record): string
    {
        $historico = $record->ultimoHistoricoPreco;

        if (! $historico) {
            return 'Sem ajustes';
        }

        $marcadorHoje = $historico->created_at?->isToday() ? ' (hoje)' : '';
        $acao = $historico->acao === 'definir_precos_exatos'
            ? 'Definição exata'
            : 'Ajuste em massa';

        return $acao.' em '.($historico->created_at?->format('d/m/Y H:i') ?? '-').$marcadorHoje;
    }

    private static function descricaoUltimoAjusteTabela(Categoria $record): ?string
    {
        $historico = $record->ultimoHistoricoPreco;

        if (! $historico) {
            return null;
        }

        if ($historico->acao === 'definir_precos_exatos') {
            return 'Venda: '.self::formatarMoeda($historico->valor_novo_venda)
                .' | Custo: '.self::formatarMoeda($historico->valor_novo_custo)
                .' | Margem: '.number_format((float) ($historico->valor_novo_percentual ?? 0), 2, ',', '.').'%';
        }

        if (($historico->valor_antigo_venda === null) || ($historico->valor_novo_venda === null)) {
            return 'Variação: sem cálculo';
        }

        $delta = (float) $historico->valor_novo_venda - (float) $historico->valor_antigo_venda;
        $sinal = $delta >= 0 ? '+' : '-';

        return 'Variação de venda: '.$sinal.self::formatarMoeda(abs($delta));
    }

    private static function resumoUltimaExecucao(Categoria $record, string $acao): string
    {
        $ultimoRegistro = ProdutoPrecoHistorico::query()
            ->where('categoria_id', $record->id)
            ->where('acao', $acao)
            ->latest('id')
            ->first();

        if (! $ultimoRegistro) {
            return 'Nenhuma atualização registrada ainda.';
        }

        $dataHora = $ultimoRegistro->created_at?->format('d/m/Y H:i') ?? 'sem data';
        $marcadorHoje = $ultimoRegistro->created_at?->isToday() ? ' (já atualizado hoje)' : '';

        if ($acao === 'definir_precos_exatos') {
            return "Última execução em {$dataHora}{$marcadorHoje} | Custo: "
                .self::formatarMoeda($ultimoRegistro->valor_novo_custo)
                .' | Margem: '.number_format((float) ($ultimoRegistro->valor_novo_percentual ?? 0), 2, ',', '.').'%'
                .' | Venda: '.self::formatarMoeda($ultimoRegistro->valor_novo_venda);
        }

        $deltaVenda = null;

        if (($ultimoRegistro->valor_antigo_venda !== null) && ($ultimoRegistro->valor_novo_venda !== null)) {
            $deltaVenda = (float) $ultimoRegistro->valor_novo_venda - (float) $ultimoRegistro->valor_antigo_venda;
        }

        $textoDelta = $deltaVenda === null
            ? 'sem variação calculável'
            : (($deltaVenda >= 0 ? '+' : '-').self::formatarMoeda(abs($deltaVenda)));

        return "Última execução em {$dataHora}{$marcadorHoje} | Variação de venda (amostra): {$textoDelta}";
    }

    private static function formatarMoeda(float|int|string|null $valor): string
    {
        return 'R$ '.number_format((float) ($valor ?? 0), 2, ',', '.');
    }

    private static function gerarListaProdutos(Categoria $record): string
    {
        $produtos = $record->produtos()->get();

        if ($produtos->isEmpty()) {
            return '<div class="p-4 text-center text-gray-500"><p class="text-sm">Nenhum produto nesta categoria</p></div>';
        }

        $html = '<div class="overflow-x-auto p-4"><table class="w-full text-sm"><thead>';
        $html .= '<tr class="border-b-2 border-gray-300"><th class="text-left p-2">Descrição</th>';
        $html .= '<th class="text-right p-2">Custo</th><th class="text-right p-2">Margem</th>';
        $html .= '<th class="text-right p-2">Venda</th></tr></thead><tbody>';

        foreach ($produtos as $produto) {
            $descricao = $produto->produto_descricao ?? 'Sem descrição';
            $custo = self::formatarMoeda($produto->produto_preco_custo);
            $margem = number_format($produto->produto_valor_percentual_venda ?? 0, 2, ',', '.').'%';
            $venda = self::formatarMoeda($produto->produto_preco_venda);

            $html .= '<tr class="border-b border-gray-200 hover:bg-gray-50">';
            $html .= "<td class='p-2'>{$descricao}</td>";
            $html .= "<td class='text-right p-2'>{$custo}</td>";
            $html .= "<td class='text-right p-2'>{$margem}</td>";
            $html .= "<td class='text-right p-2 font-semibold'>{$venda}</td>";
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    private static function gerarHistoricoAjustes(Categoria $record): string
    {
        $historicos = $record->historicosPrecos()
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        if ($historicos->isEmpty()) {
            return '<div class="p-4 text-center text-gray-500"><p class="text-sm">Nenhum histórico de ajustes</p></div>';
        }

        $html = '<div class="overflow-x-auto p-4"><table class="w-full text-xs">';
        $html .= '<thead><tr class="border-b-2 border-gray-300 bg-gray-50">';
        $html .= '<th class="text-left p-2">Data</th><th class="text-left p-2">Ação</th>';
        $html .= '<th class="text-left p-2">Produto</th><th class="text-right p-2">Venda (Antes)</th>';
        $html .= '<th class="text-right p-2">Venda (Depois)</th><th class="text-right p-2">Variação</th>';
        $html .= '<th class="text-center p-2">Status</th></tr></thead><tbody>';

        foreach ($historicos as $historico) {
            $data = $historico->created_at->format('d/m/Y H:i');
            $acao = $historico->acao === 'definir_precos_exatos' ? 'Definição Exata' : 'Ajuste em Massa';
            $produto = $historico->produto ? $historico->produto->produto_descricao : 'Produto removido';

            $vendaAntes = self::formatarMoeda($historico->valor_antigo_venda);
            $vendaDepois = self::formatarMoeda($historico->valor_novo_venda);

            $variacao = 0;
            if ($historico->valor_antigo_venda && $historico->valor_novo_venda) {
                $variacao = $historico->valor_novo_venda - $historico->valor_antigo_venda;
            }

            $sinalVariacao = $variacao >= 0 ? '+' : '-';
            $corVariacao = $variacao >= 0 ? 'text-green-600' : 'text-red-600';
            $variacaoFormatada = $sinalVariacao.self::formatarMoeda(abs($variacao));

            $status = $historico->restaurado_em ? 'Desfeito' : 'Ativo';
            $corStatus = $historico->restaurado_em ? 'text-gray-500' : 'text-green-600';

            $html .= '<tr class="border-b border-gray-200 hover:bg-gray-50">';
            $html .= "<td class='p-2'>{$data}</td>";
            $html .= "<td class='p-2'>{$acao}</td>";
            $html .= "<td class='p-2'>{$produto}</td>";
            $html .= "<td class='text-right p-2'>{$vendaAntes}</td>";
            $html .= "<td class='text-right p-2'>{$vendaDepois}</td>";
            $html .= "<td class='text-right p-2 font-semibold {$corVariacao}'>{$variacaoFormatada}</td>";
            $html .= "<td class='text-center p-2 {$corStatus}'>{$status}</td>";
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    private static function parseDecimal(mixed $value): float
    {
        if (($value === null) || ($value === '')) {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = preg_replace('/[^0-9,.-]/', '', (string) $value) ?? '';

        if (($normalized === '') || ($normalized === '-')) {
            return 0.0;
        }

        $hasComma = str_contains($normalized, ',');
        $hasDot = str_contains($normalized, '.');

        if ($hasComma && $hasDot) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif ($hasComma) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
