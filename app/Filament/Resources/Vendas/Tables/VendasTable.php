<?php

namespace App\Filament\Resources\Vendas\Tables;

use App\Exceptions\AlteracaoVendaException;
use App\Exceptions\NfeIoException;
use App\Filament\Pages\OperarVenda;
use App\Filament\Resources\Vendas\Tables\Actions\AtualizarStatusNfeEmLoteBulkAction;
use App\Filament\Resources\Vendas\Tables\Actions\CancelarVendasEmLoteBulkAction;
use App\Filament\Resources\Vendas\Tables\Actions\EnviarNfeEmLoteBulkAction;
use App\Filament\Resources\Vendas\Tables\Actions\ExportarVendasCsvBulkAction;
use App\Models\NfEmissao;
use App\Models\Venda;
use App\Services\AlteracaoVendaService;
use App\Services\Nfe\CompradorNfceService;
use App\Services\Nfe\ReenvioNfeService;
use App\Services\NfeIoService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VendasTable
{
    /** Status da NFC-e (NFe.io e tentativas locais) em português para o selo da coluna. */
    private const ROTULOS_NFE = [
        'Issued' => 'Autorizada',
        'Processing' => 'Em processamento',
        'Created' => 'Criada',
        'Error' => 'Erro',
        'Failed' => 'Erro',
        'IssueFailed' => 'Erro na emissão',
        NfEmissao::STATUS_FALHA_ENVIO => 'Falha no envio',
        NfEmissao::STATUS_RESERVADO => 'Número reservado',
        'Cancelled' => 'Cancelada',
        'CancelamentoSolicitado' => 'Cancelamento solicitado',
        'CancelFailed' => 'Cancelamento recusado',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'cliente',
                'nfEmissaoAtual',
                'sessaoCaixa.user',
                'sessaoCaixa.caixa',
                'pagamentos.opcaoPagamento' => fn ($opcao) => $opcao->withTrashed(),
            ]))
            ->groups([self::grupoSessaoCaixa()])
            ->defaultGroup('venda_sessao_caixa_id')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('sessaoCaixa.caixa.caixa_nome')
                    ->label('Caixa')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cliente.cliente_nome')
                    ->label('Cliente')
                    ->sortable()
                    ->searchable()
                    ->default('—'),

                TextColumn::make('venda_valor_itens')
                    ->label('Itens')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->summarize(Sum::make('sum')->money('BRL')->label('Itens')),

                TextColumn::make('venda_valor_desconto')
                    ->label('Desconto')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->summarize(Sum::make('sum')->money('BRL')->label('Desconto')),

                TextColumn::make('venda_valor_total')
                    ->label('Total')
                    ->money('BRL')
                    ->sortable()
                    ->weight('bold')
                    ->summarize(Sum::make('sum')->money('BRL')->label('Total')),

                TextColumn::make('venda_valor_pago')
                    ->label('Pago')
                    ->money('BRL')
                    ->sortable()
                    ->summarize(Sum::make('sum')->money('BRL')->label('Pago')),

                TextColumn::make('operador')
                    ->label('Operador')
                    ->state(fn (Venda $record): ?string => $record->sessaoCaixa?->user?->name)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('pagamentos')
                    ->label('Pagamentos')
                    ->state(fn (Venda $record): array => $record->pagamentos->map(function ($pagamento): string {
                        $linha = ($pagamento->opcaoPagamento?->opcaopag_nome ?? 'Forma não identificada').' — R$ '.number_format((float) $pagamento->pg_venda_valor_pagamento, 2, ',', '.');

                        return (float) $pagamento->pg_venda_valor_troco > 0
                            ? $linha.' · troco R$ '.number_format((float) $pagamento->pg_venda_valor_troco, 2, ',', '.')
                            : $linha;
                    })->all())
                    ->listWithLineBreaks()
                    ->limitList(1)
                    ->expandableLimitedList()
                    ->placeholder('—'),

                TextColumn::make('venda_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'FINALIZADA' => 'success',
                        'CANCELADA' => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('venda_datahora_iniciada')
                    ->label('Iniciada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('venda_datahora_finalizada')
                    ->label('Finalizada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('venda_status_nfe')
                    ->label('NFC-e')
                    ->badge()
                    ->state(fn (Venda $record): string => $record->statusNfeExibicao() ?? '—')
                    ->formatStateUsing(fn (string $state): string => self::ROTULOS_NFE[$state] ?? $state)
                    ->tooltip(fn (Venda $record): ?string => self::detalheDaNota($record))
                    ->color(fn (?string $state): string => match ($state) {
                        'Issued' => 'success',
                        'Error', 'Failed', 'IssueFailed', NfEmissao::STATUS_FALHA_ENVIO => 'danger',
                        'Cancelled' => 'gray',
                        'CancelamentoSolicitado' => 'warning',
                        '—' => 'gray',
                        default => 'info',
                    }),

                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters(VendasFiltros::filtros(), layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormSchema(VendasFiltros::secoes(...))
            ->deferFilters(false)
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistSearchInSession()
            // Antes das células: com tantas colunas, o menu de ações ficaria fora da tela à direita.
            ->recordActionsPosition(RecordActionsPosition::BeforeCells)
            ->recordActions([
                ActionGroup::make([
                    Action::make('operar')
                        ->label('Operar')
                        ->icon('heroicon-o-shopping-cart')
                        ->url(fn (Venda $record) => OperarVenda::getUrl(['venda' => $record]))
                        ->visible(fn (Venda $record) => $record->venda_status === 'INICIADA'),

                    Action::make('imprimirDanfe')
                        ->label('DANFE')
                        ->icon('heroicon-o-printer')
                        ->url(fn (Venda $record) => route('venda.imprimir_NFE', ['id_nfe' => $record->venda_id_nfe]))
                        ->openUrlInNewTab()
                        ->visible(fn (Venda $record) => filled($record->venda_id_nfe)),

                    Action::make('enviarNfe')
                        ->label(fn (Venda $record): string => $record->nfEmissaoAtual ? 'Reenviar NFC-e' : 'Enviar NFC-e')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('primary')
                        ->authorize(fn (): bool => self::podeEmitirNfe())
                        ->visible(fn (Venda $record, ReenvioNfeService $reenvio): bool => self::podeEmitirNfe() && $record->venda_status === 'FINALIZADA' && $reenvio->motivoBloqueio($record) === null)
                        ->requiresConfirmation()
                        ->modalDescription(fn (Venda $record): string => self::descricaoDoEnvio($record))
                        ->action(function (Venda $record, ReenvioNfeService $reenvio): void {
                            try {
                                $status = $reenvio->reenviar($record);
                            } catch (NfeIoException $e) {
                                Notification::make()->title('Não foi possível enviar a NFC-e')->body($e->getMessage())->danger()->send();

                                return;
                            }

                            Notification::make()->title('NFC-e enviada')->body("Status atual: {$status}. A autorização chega pela NFe.io.")->success()->send();
                        }),

                    Action::make('atualizarStatusNfe')
                        ->label('Atualizar status NFC-e')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->authorize(fn (): bool => self::podeEmitirNfe())
                        ->visible(fn (Venda $record, ReenvioNfeService $reenvio): bool => self::podeEmitirNfe() && $reenvio->podeAtualizarStatus($record))
                        ->action(function (Venda $record, ReenvioNfeService $reenvio): void {
                            try {
                                $status = $reenvio->atualizarStatus($record);
                            } catch (NfeIoException $e) {
                                Notification::make()->title('Não foi possível consultar a NFe.io')->body($e->getMessage())->danger()->send();

                                return;
                            }

                            Notification::make()->title("Status da NFC-e: {$status}")->success()->send();
                        }),

                    self::acaoAlterarVenda(),

                    Action::make('cancelarNfe')
                        ->label('Cancelar NFC-e')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('O cancelamento é enviado à NFe.io e processado de forma assíncrona — o status só reflete a confirmação real quando o webhook chegar.')
                        ->action(function (Venda $record, NfeIoService $service): void {
                            try {
                                $service->cancelar($record->venda_id_nfe);
                                $record->update(['venda_status_nfe' => 'CancelamentoSolicitado']);
                                NfEmissao::espelharStatus($record->venda_id_nfe, 'CancelamentoSolicitado');

                                Notification::make()
                                    ->title('Cancelamento solicitado')
                                    ->success()
                                    ->send();
                            } catch (NfeIoException $e) {
                                Notification::make()
                                    ->title('Não foi possível cancelar a NFC-e')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->visible(fn (Venda $record) => $record->venda_status_nfe === 'Issued'),
                ])
                    ->label('Ações')
                    ->icon(Heroicon::EllipsisVertical)
                    ->tooltip('Ações da venda')
                    ->color('gray'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    EnviarNfeEmLoteBulkAction::make(),
                    AtualizarStatusNfeEmLoteBulkAction::make(),
                    ExportarVendasCsvBulkAction::make(),
                    CancelarVendasEmLoteBulkAction::make(),
                ]),
            ]);
    }

    /** Vendas agrupadas pela sessão de caixa em que foram feitas, com subtotais pelos Sum das colunas. */
    private static function grupoSessaoCaixa(): Group
    {
        return Group::make('venda_sessao_caixa_id')
            ->label('Sessão de caixa')
            ->titlePrefixedWithLabel(false)
            ->getKeyFromRecordUsing(fn (Venda $record): string => (string) ($record->venda_sessao_caixa_id ?? ''))
            ->getTitleFromRecordUsing(fn (Venda $record): string => $record->sessaoCaixa
                ? "Sessão #{$record->sessaoCaixa->id} — ".($record->sessaoCaixa->caixa?->caixa_nome ?? 'Caixa').' — '.($record->sessaoCaixa->user?->name ?? 'Operador')
                : 'Sem sessão de caixa')
            ->getDescriptionFromRecordUsing(fn (Venda $record): ?string => $record->sessaoCaixa
                ? 'Aberta em '.$record->sessaoCaixa->sessaocaixa_data_hora_abertura?->format('d/m/Y H:i').' · '.$record->sessaoCaixa->sessaocaixa_status
                : null)
            ->orderQueryUsing(fn (Builder $query, string $direction): Builder => $query->orderBy('venda_sessao_caixa_id', $direction === 'asc' ? 'asc' : 'desc'))
            ->scopeQueryByKeyUsing(fn (Builder $query, ?string $key): Builder => blank($key)
                ? $query->whereNull('venda_sessao_caixa_id')
                : $query->where('venda_sessao_caixa_id', (int) $key))
            ->collapsible();
    }

    private static function podeEmitirNfe(): bool
    {
        return auth()->user()?->can('emitir:nfe') ?? false;
    }

    /** Último erro de envio e/ou aviso de comprador não identificado, para o tooltip da coluna. */
    private static function detalheDaNota(Venda $venda): ?string
    {
        $emissao = $venda->nfEmissaoAtual;

        return collect([
            $emissao?->erro_envio,
            $emissao?->comprador_motivo_omissao ? 'Emitida sem identificar o cliente: '.$emissao->comprador_motivo_omissao : null,
        ])->filter()->implode(' — ') ?: null;
    }

    private static function descricaoDoEnvio(Venda $venda): string
    {
        $emissao = $venda->nfEmissaoAtual;
        $linhas = [];

        if ($emissao?->erro_envio) {
            $linhas[] = 'Último erro: '.$emissao->erro_envio;
        }

        if ($venda->venda_status_nfe === 'Cancelled') {
            $linhas[] = 'A nota anterior foi cancelada: será emitida uma nova, com outro número.';
        }

        if ($venda->cliente && ($motivo = app(CompradorNfceService::class)->motivoSemIdentificacao($venda->cliente))) {
            $linhas[] = "O cadastro do cliente não está completo ({$motivo}): a nota sairá sem identificar o cliente.";
        }

        return $linhas === [] ? 'Envia a NFC-e desta venda para a NFe.io.' : implode("\n", $linhas);
    }

    private static function podeAlterarVenda(): bool
    {
        return auth()->user()?->can('alterar_finalizada:venda') ?? false;
    }

    /**
     * Reabre a venda finalizada no PDV (OperarVenda) para alterar tudo: itens,
     * cliente e pagamentos. Venda já em alteração só continua de onde parou.
     */
    private static function acaoAlterarVenda(): Action
    {
        return Action::make('alterarVenda')
            ->label(fn (Venda $record): string => $record->alteracaoAberta ? 'Continuar alteração' : 'Alterar venda')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn (Venda $record): bool => self::podeAlterarVenda() && ($record->venda_status === 'FINALIZADA' || (bool) $record->alteracaoAberta))
            ->authorize(fn (): bool => self::podeAlterarVenda())
            ->disabled(fn (Venda $record): bool => ! $record->alteracaoAberta && app(AlteracaoVendaService::class)->motivoBloqueio($record) !== null)
            ->tooltip(fn (Venda $record): ?string => $record->alteracaoAberta ? null : app(AlteracaoVendaService::class)->motivoBloqueio($record))
            ->url(fn (Venda $record): ?string => $record->alteracaoAberta ? OperarVenda::getUrl(['venda' => $record]) : null)
            ->modalHeading(fn (Venda $record): string => "Alterar venda #{$record->id}")
            ->modalDescription('A venda volta para a Nova Venda: o caixa, o estoque e o título a receber desta venda são desfeitos até você finalizar de novo. Se desistir, "Descartar alterações" restaura tudo como estava (exceto itens divididos durante a alteração).')
            ->modalSubmitActionLabel('Reabrir e alterar')
            ->schema([
                Textarea::make('motivo')
                    ->label('Motivo da alteração')
                    ->placeholder('Ex.: item lançado errado, cliente trocado, forma de pagamento incorreta')
                    ->required()
                    ->minLength(5)
                    ->rows(2),
            ])
            ->action(function (Venda $record, array $data, AlteracaoVendaService $alteracao, $livewire): void {
                try {
                    $alteracao->reabrir($record, $data['motivo']);
                } catch (AlteracaoVendaException $e) {
                    Notification::make()->title('Não foi possível reabrir a venda')->body($e->getMessage())->danger()->send();

                    return;
                }

                $livewire->redirect(OperarVenda::getUrl(['venda' => $record]));
            });
    }
}
