<?php

namespace App\Filament\Resources\Categorias;

use App\Filament\Resources\Categorias\Pages\ManageCategorias;
use App\Models\Categoria;
use App\Models\ProdutoPrecoHistorico;
use BackedEnum;
use Filament\Actions\Action as ActionsAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use Leandrocfe\FilamentPtbrFormFields\Money;

class CategoriaResource extends Resource
{
    protected static ?string $model = Categoria::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'categoria_nome';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('categoria_nome'),
                Toggle::make('categoria_cardapio')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('categoria_nome')
            ->columns([
                TextColumn::make('categoria_nome')
                    ->searchable(),
                IconColumn::make('categoria_cardapio')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionsAction::make('ajustar_precos_venda')
                    ->label('Ajustar preços de venda')
                    ->icon('heroicon-o-currency-dollar')
                    ->modalHeading('Aplicar reajuste na categoria')
                    ->modalDescription('Reajuste coletivo por tipo de operação e unidade de cálculo.')
                    ->modalWidth('lg')
                    ->form([
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
                            ? number_format(abs($ajuste), 2, ',', '.') . '%'
                            : 'R$ ' . number_format(abs($ajuste), 2, ',', '.');
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
                    ->label('Definir preços exatos')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->modalHeading('Definir preços exatos da categoria')
                    ->modalDescription('Aplica os mesmos valores de custo, margem e venda para todos os produtos da categoria.')
                    ->modalWidth('lg')
                    ->form([
                        Money::make('preco_custo_exato')
                            ->label('Preço de custo exato (R$)')
                            ->placeholder('Ex: 10,00')
                            ->live()
                            ->required()
                            ->helperText('Novo valor de custo para todos os produtos da categoria'),

                        TextInput::make('percentual_venda_exato')
                            ->label('Percentual de venda exato (%)')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('%')
                            ->placeholder('Ex: 35')
                            ->live()
                            ->required()
                            ->helperText('Nova margem percentual aplicada a todos os produtos (com custo 0, deve ser 100%)'),

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
                        $custo = round(self::parseDecimal($data['preco_custo_exato'] ?? 0), 2);
                        $percentual = round((float) ($data['percentual_venda_exato'] ?? 0), 2);
                        $vendaInformada = round(self::parseDecimal($data['preco_venda_exato'] ?? 0), 2);
                        $loteUuid = (string) Str::uuid();
                        $usuarioId = auth()->id();

                        if (($custo < 0) || ($percentual < 0)) {
                            Notification::make()
                                ->title('Valores inválidos')
                                ->body('Informe custo e percentual maiores ou iguais a zero.')
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($custo == 0.0) {
                            if (abs($percentual - 100.0) > 0.0001) {
                                Notification::make()
                                    ->title('Percentual inválido para custo zero')
                                    ->body('Quando o custo for zero, o percentual deve ser exatamente 100%.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            if ($vendaInformada <= 0) {
                                Notification::make()
                                    ->title('Preço de venda inválido')
                                    ->body('Com custo igual a zero, informe um preço de venda maior que zero.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $vendaFinal = $vendaInformada;
                        } else {
                            $vendaFinal = round($custo * (1 + ($percentual / 100)), 2);
                        }

                        $atualizados = 0;

                        $record->produtos()
                            ->chunkById(100, function ($produtos) use ($record, $loteUuid, $usuarioId, $custo, $percentual, $vendaFinal, &$atualizados) {
                                foreach ($produtos as $produto) {
                                    self::registrarHistoricoProduto(
                                        categoriaId: $record->id,
                                        produto: $produto,
                                        acao: 'definir_precos_exatos',
                                        loteUuid: $loteUuid,
                                        usuarioId: $usuarioId,
                                        novoCusto: $custo,
                                        novoPercentual: $percentual,
                                        novoVenda: $vendaFinal,
                                    );

                                    $produto->forceFill([
                                        'produto_preco_custo' => $custo,
                                        'produto_valor_percentual_venda' => $percentual,
                                        'produto_preco_venda' => $vendaFinal,
                                    ])->saveQuietly();

                                    $atualizados++;
                                }
                            });

                        Notification::make()
                            ->title('Preços definidos com sucesso')
                            ->body(
                                "{$atualizados} produtos atualizados para Custo R$ " . number_format($custo, 2, ',', '.')
                                . " | Margem " . number_format($percentual, 2, ',', '.') . '%'
                                . " | Venda R$ " . number_format($vendaFinal, 2, ',', '.')
                            )
                            ->success()
                            ->send();
                    }),
                ActionsAction::make('desfazer_ultimo_reajuste')
                    ->label('Desfazer último reajuste')
                    ->icon('heroicon-o-arrow-uturn-left')
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
                EditAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
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
