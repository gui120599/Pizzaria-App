<?php

namespace App\Filament\Resources\Vendas\Tables;

use App\Exceptions\CorrecaoPagamentoException;
use App\Exceptions\NfeIoException;
use App\Filament\Pages\OperarVenda;
use App\Filament\Support\CorrecaoPagamentoFormulario;
use App\Models\Cliente;
use App\Models\NfEmissao;
use App\Models\Venda;
use App\Services\AlteracaoVendaService;
use App\Services\CorrecaoPagamentoService;
use App\Services\Nfe\CompradorNfceService;
use App\Services\Nfe\ReenvioNfeService;
use App\Services\NfeIoService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VendasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['cliente', 'nfEmissaoAtual']))
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('sessaoCaixa.caixa.caixa_nome')
                    ->label('Caixa')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('cliente.cliente_nome')
                    ->label('Cliente')
                    ->sortable()
                    ->searchable()
                    ->default('—'),

                TextColumn::make('venda_valor_itens')
                    ->label('Itens')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('venda_valor_desconto')
                    ->label('Desconto')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('venda_valor_total')
                    ->label('Total')
                    ->money('BRL')
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('venda_valor_pago')
                    ->label('Pago')
                    ->money('BRL')
                    ->sortable(),

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
                    ->sortable(),

                TextColumn::make('venda_datahora_finalizada')
                    ->label('Finalizada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('venda_status_nfe')
                    ->label('NFC-e')
                    ->badge()
                    ->state(fn (Venda $record): string => $record->statusNfeExibicao() ?? '—')
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
            ->filters([
                SelectFilter::make('venda_status')
                    ->label('Status')
                    ->options([
                        'INICIADA' => 'Iniciada',
                        'FINALIZADA' => 'Finalizada',
                        'CANCELADA' => 'Cancelada',
                    ]),
            ])
            ->recordActions([
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
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function podeEmitirNfe(): bool
    {
        return auth()->user()?->can('emitir:nfe') ?? false;
    }

    private static function podeCorrigirPagamento(): bool
    {
        return auth()->user()?->can('corrigir:pagamento_venda') ?? false;
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

    private static function acaoAlterarVenda(): Action
    {
        return Action::make('alterarVenda')
            ->label('Alterar venda')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn (Venda $record): bool => self::podeEmitirNfe() && $record->venda_status === 'FINALIZADA')
            ->authorize(fn (): bool => self::podeEmitirNfe())
            ->disabled(fn (Venda $record): bool => self::motivoBloqueioAlteracao($record) !== null)
            ->tooltip(fn (Venda $record): ?string => self::motivoBloqueioAlteracao($record))
            ->modalHeading(fn (Venda $record): string => "Alterar venda #{$record->id}")
            ->modalDescription('Só para vendas sem NFC-e autorizada. Depois de alterar, use "Enviar NFC-e".')
            ->modalSubmitActionLabel('Salvar alteração')
            ->schema(fn (Venda $record): array => [
                ToggleButtons::make('o_que')
                    ->label('O que alterar')
                    ->options([
                        'cliente' => 'Cliente',
                        'pagamento' => 'Forma de pagamento',
                    ])
                    ->disableOptionWhen(fn (string $value): bool => match ($value) {
                        'cliente' => app(AlteracaoVendaService::class)->motivoBloqueioCliente($record) !== null,
                        default => ! self::podeCorrigirPagamento() || app(CorrecaoPagamentoService::class)->motivoBloqueioVenda($record) !== null,
                    })
                    ->default(app(AlteracaoVendaService::class)->motivoBloqueioCliente($record) === null ? 'cliente' : 'pagamento')
                    ->required()
                    ->inline()
                    ->live(),

                Select::make('cliente_id')
                    ->label('Cliente (vazio remove o cliente da venda)')
                    ->options(fn (): array => Cliente::query()->orderBy('cliente_nome')->limit(50)->pluck('cliente_nome', 'id')->all())
                    ->getSearchResultsUsing(fn (string $search): array => Cliente::query()
                        ->where(fn ($query) => $query->where('cliente_nome', 'like', "%{$search}%")->orWhere('cliente_cpf', 'like', "%{$search}%"))
                        ->orderBy('cliente_nome')
                        ->limit(50)
                        ->pluck('cliente_nome', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Cliente::find($value)?->cliente_nome)
                    ->default($record->venda_cliente_id)
                    ->searchable()
                    ->live()
                    ->visible(fn (Get $get): bool => $get('o_que') === 'cliente'),
                Text::make(fn (Get $get): string => (($motivo = self::motivoCadastroIncompleto($get('cliente_id'))) !== null)
                    ? "Cadastro incompleto para a NFC-e ({$motivo}): a nota sairá sem identificar o cliente."
                    : 'Cadastro completo: o cliente será identificado na NFC-e.')
                    ->visible(fn (Get $get): bool => $get('o_que') === 'cliente' && filled($get('cliente_id'))),
                Textarea::make('justificativa_cliente')
                    ->label('Justificativa')
                    ->rows(2)
                    ->visible(fn (Get $get): bool => $get('o_que') === 'cliente'),

                ...CorrecaoPagamentoFormulario::schema(
                    CorrecaoPagamentoFormulario::pagamentosDaVenda($record),
                    fn (Get $get): bool => $get('o_que') === 'pagamento',
                ),
            ])
            ->action(function (Venda $record, array $data, AlteracaoVendaService $alteracao, CorrecaoPagamentoService $correcoes): void {
                try {
                    if ($data['o_que'] === 'cliente') {
                        $alteracao->trocarCliente(
                            $record,
                            filled($data['cliente_id'] ?? null) ? (int) $data['cliente_id'] : null,
                            filled($data['justificativa_cliente'] ?? null) ? $data['justificativa_cliente'] : null,
                        );
                    } else {
                        if (! self::podeCorrigirPagamento()) {
                            throw new CorrecaoPagamentoException('Você não tem permissão para corrigir pagamentos.');
                        }

                        CorrecaoPagamentoFormulario::aplicar($record->id, $data, $correcoes);
                    }
                } catch (CorrecaoPagamentoException $e) {
                    Notification::make()->title('Alteração não feita')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title("Venda #{$record->id} alterada")->success()->send();
            });
    }

    /** Só some o botão quando nada pode ser alterado; o motivo vira tooltip. */
    private static function motivoBloqueioAlteracao(Venda $venda): ?string
    {
        $cliente = app(AlteracaoVendaService::class)->motivoBloqueioCliente($venda);

        if ($cliente === null) {
            return null;
        }

        $pagamento = self::podeCorrigirPagamento() ? app(CorrecaoPagamentoService::class)->motivoBloqueioVenda($venda) : 'sem permissão';

        return $pagamento === null ? null : $cliente;
    }

    private static function motivoCadastroIncompleto(mixed $clienteId): ?string
    {
        $cliente = filled($clienteId) ? Cliente::find($clienteId) : null;

        return $cliente ? app(CompradorNfceService::class)->motivoSemIdentificacao($cliente) : null;
    }
}
