<?php

namespace App\Filament\Resources\CompraPendencias\Tables;

use App\Enums\Compras\StatusPendencia;
use App\Enums\Compras\TipoPendenciaCompra;
use App\Filament\Pages\PagamentosAConfirmar;
use App\Filament\Resources\Compras\CompraResource;
use App\Models\CompraPendencia;
use App\Models\Produto;
use App\Services\Compras\PendenciaService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

class CompraPendenciasTable
{
    /** Tipos que se resolvem só marcando como resolvido (informativos ou tratados em outra tela). */
    private const RESOLUCAO_SIMPLES = [
        TipoPendenciaCompra::FornecedorNovo,
        TipoPendenciaCompra::FornecedorSemParametros,
        TipoPendenciaCompra::PrecoForaPadrao,
        TipoPendenciaCompra::DivergenciaValor,
        TipoPendenciaCompra::NfeCancelada,
        TipoPendenciaCompra::OperacaoFiscal,
        TipoPendenciaCompra::XmlInvalido,
        TipoPendenciaCompra::CaixaPdvFechado,
        TipoPendenciaCompra::PagamentoAConfirmar,
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('tipo')->label('Tipo')->badge(),
                TextColumn::make('status')->label('Situação')->badge(),
                TextColumn::make('compra_id')
                    ->label('Compra')
                    ->state(fn (CompraPendencia $record): string => '#'.$record->compra_id
                        .($record->compra?->compra_numero ? ' · NF '.$record->compra->compra_numero : ''))
                    ->description(fn (CompraPendencia $record): ?string => $record->compra?->prestador?->nome_exibicao)
                    ->url(fn (CompraPendencia $record): string => CompraResource::getUrl('edit', ['record' => $record->compra_id])),
                TextColumn::make('detalhe')
                    ->label('Detalhe')
                    ->state(fn (CompraPendencia $record): string => self::detalhe($record))
                    ->wrap(),
                TextColumn::make('sugestao')
                    ->label('Sugestão do agente')
                    ->state(fn (CompraPendencia $record): ?string => self::sugestao($record))
                    ->description(fn (CompraPendencia $record): ?string => $record->justificativa)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('created_at')->label('Aberta em')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('resolvidaPor.name_first')->label('Resolvida por')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('resolvida_em')->label('Resolvida em')->dateTime('d/m/Y H:i')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tipo')->label('Tipo')->options(TipoPendenciaCompra::class),
            ])
            ->recordActions([
                self::resolverVinculo(),
                self::aprovarSugestao(),
                self::escolherLancamento(),
                ActionGroup::make([
                    Action::make('pagamentos')
                        ->label('Ir para pagamentos a confirmar')
                        ->icon(Heroicon::OutlinedBanknotes)
                        ->visible(fn (CompraPendencia $record): bool => $record->estaEmAberto()
                            && in_array($record->tipo, [TipoPendenciaCompra::CaixaPdvFechado, TipoPendenciaCompra::PagamentoAConfirmar], true))
                        ->url(fn (): string => PagamentosAConfirmar::getUrl()),
                    self::marcarResolvida(),
                    self::descartar(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('aprovarSugestoes')
                        ->label('Aprovar sugestões')
                        ->icon(Heroicon::OutlinedCheckBadge)
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('Aplica a sugestão do agente em cada pendência selecionada que estiver aguardando aprovação. As demais são ignoradas.')
                        ->action(function (Collection $records, PendenciaService $pendencias): void {
                            [$aprovadas, $erros] = [0, []];
                            foreach ($records as $pendencia) {
                                if ($pendencia->status !== StatusPendencia::Sugerida) {
                                    continue;
                                }
                                try {
                                    $pendencias->aprovarSugestao($pendencia, auth()->user());
                                    $aprovadas++;
                                } catch (ValidationException $e) {
                                    $erros[] = "#{$pendencia->id}: ".collect($e->errors())->flatten()->first();
                                }
                            }
                            self::notificarLote("{$aprovadas} sugestão(ões) aprovada(s).", $erros);
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('descartar')
                        ->label('Descartar')
                        ->icon(Heroicon::OutlinedXMark)
                        ->color('gray')
                        ->schema([Textarea::make('motivo')->label('Motivo')->required()->rows(2)])
                        ->action(function (Collection $records, array $data, PendenciaService $pendencias): void {
                            $descartadas = 0;
                            foreach ($records as $pendencia) {
                                if ($pendencia->estaEmAberto()) {
                                    $pendencias->descartar($pendencia, auth()->user(), $data['motivo']);
                                    $descartadas++;
                                }
                            }
                            self::notificarLote("{$descartadas} pendência(s) descartada(s).", []);
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    private static function resolverVinculo(): Action
    {
        return Action::make('resolverVinculo')
            ->label('Resolver')
            ->icon(Heroicon::OutlinedLink)
            ->color('primary')
            ->visible(fn (CompraPendencia $record): bool => $record->estaEmAberto()
                && in_array($record->tipo, [TipoPendenciaCompra::ProdutoNaoIdentificado, TipoPendenciaCompra::ConversaoUnidade], true))
            ->modalHeading(fn (CompraPendencia $record): string => 'Vincular: '.($record->item?->ci_descricao_fornecedor ?? 'item'))
            ->modalDescription('O vínculo vale para as próximas notas deste fornecedor com o mesmo código.')
            ->fillForm(fn (CompraPendencia $record): array => [
                'produto_id' => $record->sugestao['produto_id'] ?? null,
                'fator_conversao' => $record->sugestao['fator_conversao'] ?? 1,
            ])
            ->schema([
                Select::make('produto_id')
                    ->label('Produto do estoque')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Produto::query()
                        ->where('produto_descricao', 'like', "%{$search}%")
                        ->orderBy('produto_descricao')->limit(50)->pluck('produto_descricao', 'id')->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Produto::query()->whereKey($value)->value('produto_descricao'))
                    ->required(),
                TextInput::make('fator_conversao')
                    ->label('Fator de conversão')
                    ->numeric()
                    ->minValue(0.0001)
                    ->required()
                    ->helperText(fn (CompraPendencia $record): string => '1 '.($record->item?->ci_unidade_compra ?: 'unidade da nota').' = X unidades de estoque.'),
            ])
            ->action(function (CompraPendencia $record, array $data, PendenciaService $pendencias, Action $action): void {
                try {
                    $pendencias->resolverVinculo($record, (int) $data['produto_id'], (float) $data['fator_conversao'], auth()->user());
                } catch (ValidationException $e) {
                    Notification::make()->title('Não foi possível resolver')->body(collect($e->errors())->flatten()->first())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Item vinculado')->success()->send();
            });
    }

    private static function aprovarSugestao(): Action
    {
        return Action::make('aprovarSugestao')
            ->label('Aprovar')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (CompraPendencia $record): bool => $record->status === StatusPendencia::Sugerida)
            ->requiresConfirmation()
            ->modalDescription(fn (CompraPendencia $record): string => (self::sugestao($record) ?? '').($record->justificativa ? " — {$record->justificativa}" : ''))
            ->action(function (CompraPendencia $record, PendenciaService $pendencias, Action $action): void {
                try {
                    $pendencias->aprovarSugestao($record, auth()->user());
                } catch (ValidationException $e) {
                    Notification::make()->title('Não foi possível aprovar')->body(collect($e->errors())->flatten()->first())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Sugestão aprovada')->success()->send();
            });
    }

    private static function escolherLancamento(): Action
    {
        return Action::make('escolherLancamento')
            ->label('Escolher lançamento')
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->visible(fn (CompraPendencia $record): bool => $record->estaEmAberto() && $record->tipo === TipoPendenciaCompra::LancamentoAmbiguo)
            ->fillForm(fn (CompraPendencia $record): array => ['lancamento_id' => $record->sugestao['lancamento_id'] ?? null])
            ->schema([
                Select::make('lancamento_id')
                    ->label('Lançamento já registrado desta compra')
                    ->options(fn (CompraPendencia $record): array => collect($record->dados['candidatos'] ?? [])
                        ->mapWithKeys(fn (array $c): array => [$c['lancamento_id'] => "#{$c['lancamento_id']} — {$c['descricao']} — "
                            .Number::currency((float) $c['valor'], 'BRL', 'pt_BR').' — vence '.($c['vencimento'] ? date('d/m/Y', strtotime($c['vencimento'])) : '—')])
                        ->all())
                    ->required(),
            ])
            ->action(function (CompraPendencia $record, array $data, PendenciaService $pendencias, Action $action): void {
                try {
                    $pendencias->resolverLancamento($record, (int) $data['lancamento_id'], auth()->user());
                } catch (ValidationException $e) {
                    Notification::make()->title('Não foi possível vincular')->body(collect($e->errors())->flatten()->first())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Lançamento vinculado à compra')->success()->send();
            });
    }

    private static function marcarResolvida(): Action
    {
        return Action::make('marcarResolvida')
            ->label('Marcar como resolvida')
            ->icon(Heroicon::OutlinedCheck)
            ->visible(fn (CompraPendencia $record): bool => $record->estaEmAberto() && in_array($record->tipo, self::RESOLUCAO_SIMPLES, true))
            ->schema([Textarea::make('observacao')->label('Observação')->rows(2)])
            ->action(function (CompraPendencia $record, array $data, PendenciaService $pendencias): void {
                $pendencias->resolver($record, auth()->user(), array_filter(['observacao' => $data['observacao'] ?? null]));
                Notification::make()->title('Pendência resolvida')->success()->send();
            });
    }

    private static function descartar(): Action
    {
        return Action::make('descartar')
            ->label('Descartar')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->visible(fn (CompraPendencia $record): bool => $record->estaEmAberto())
            ->schema([Textarea::make('motivo')->label('Motivo')->required()->rows(2)])
            ->action(function (CompraPendencia $record, array $data, PendenciaService $pendencias): void {
                $pendencias->descartar($record, auth()->user(), $data['motivo']);
                Notification::make()->title('Pendência descartada')->success()->send();
            });
    }

    /** Resumo legível do que a pendência pede, a partir de `dados`. */
    public static function detalhe(CompraPendencia $record): string
    {
        $dados = $record->dados ?? [];
        $moeda = fn (mixed $valor): string => Number::currency((float) $valor, 'BRL', 'pt_BR');

        return match ($record->tipo) {
            TipoPendenciaCompra::ProdutoNaoIdentificado, TipoPendenciaCompra::ConversaoUnidade => trim(
                ($dados['descricao'] ?? $record->item?->ci_descricao_fornecedor ?? '').' · cód. '.($dados['codigo_fornecedor'] ?? '—')
                .' · '.Number::format((float) ($dados['quantidade'] ?? 0), locale: 'pt_BR').' '.($dados['unidade'] ?? '')
                .' × '.$moeda($dados['valor_unitario'] ?? 0)
                .(filled($dados['ean'] ?? null) ? ' · EAN '.$dados['ean'] : '')
            ),
            TipoPendenciaCompra::PrecoForaPadrao => ($record->item?->ci_descricao_fornecedor ?? 'Item').': '.$moeda($dados['custo_unitario'] ?? 0)
                .' contra '.$moeda($dados['ultimo_custo_unitario'] ?? 0).' na última compra ('
                .Number::format((float) ($dados['variacao_pct'] ?? 0), 2, locale: 'pt_BR').'%)',
            TipoPendenciaCompra::DivergenciaValor => 'Duplicatas somam '.$moeda($dados['soma_duplicatas'] ?? 0).', nota '.$moeda($dados['valor_nota'] ?? 0).'. Baixa automática suspensa.',
            TipoPendenciaCompra::LancamentoAmbiguo => count($dados['candidatos'] ?? []).' lançamentos à mão batem com esta nota: escolha qual é.',
            TipoPendenciaCompra::FornecedorNovo => 'Fornecedor cadastrado pela nota ('.($dados['nome'] ?? '').'). Confira o cadastro.',
            TipoPendenciaCompra::FornecedorSemParametros => 'Configure as condições de compra do fornecedor (regra de baixa, conta, entrada automática).',
            default => (string) ($dados['motivo'] ?? $record->tipo->getLabel()),
        };
    }

    /** Sugestão do agente em texto, quando houver. */
    public static function sugestao(CompraPendencia $record): ?string
    {
        $sugestao = $record->sugestao;
        if (blank($sugestao)) {
            return null;
        }

        $confianca = $record->confianca !== null ? ' ('.Number::percentage((float) $record->confianca * 100, locale: 'pt_BR').')' : '';

        return match ($sugestao['acao'] ?? null) {
            'vincular' => 'Vincular a "'.(Produto::query()->whereKey($sugestao['produto_id'] ?? 0)->value('produto_descricao') ?? '?')
                .'", fator '.Number::format((float) ($sugestao['fator_conversao'] ?? 1), locale: 'pt_BR').$confianca,
            'novo_produto' => 'Cadastrar produto "'.($sugestao['produto']['produto_descricao'] ?? '?').'"'.$confianca,
            'lancamento' => 'Lançamento #'.($sugestao['lancamento_id'] ?? '?').$confianca,
            default => null,
        };
    }

    /** @param  list<string>  $erros */
    private static function notificarLote(string $titulo, array $erros): void
    {
        Notification::make()
            ->title($titulo)
            ->body($erros === [] ? null : implode("\n", $erros))
            ->{$erros === [] ? 'success' : 'warning'}()
            ->send();
    }
}
