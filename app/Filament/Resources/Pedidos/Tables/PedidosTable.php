<?php

namespace App\Filament\Resources\Pedidos\Tables;

use App\Enums\PedidoOrigemEnum;
use App\Enums\SituacaoPagamentoPedidoEnum;
use App\Enums\StatusPedidoEnum;
use App\Enums\StonePedidoModo;
use App\Enums\TipoAtendimentoEnum;
use App\Enums\UrgenciaPedidoEnum;
use App\Exceptions\StoneConnectException;
use App\Filament\Exports\PedidoExporter;
use App\Filament\Pages\AtenderPedido;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Filament\Resources\Pedidos\Pages\ViewPedido;
use App\Filament\Support\PedidoExclusaoActions;
use App\Filament\Support\PedidoStatusActions;
use App\Models\Maquininha;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\StonePedido;
use App\Services\Stone\StoneRecebimentoService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ExportBulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

class PedidosTable
{
    /** Abas em que a lista é um "estado atual" e não um histórico: ordenam por número, sem janela de data. */
    private const ABAS_SEM_DATA = ['rascunhos', 'lixeira'];

    public static function configure(Table $table): Table
    {
        // Existe maquininha Stone? Uma consulta por requisição, e só se alguma linha chegar a perguntar.
        $stoneDisponivelEmCache = null;
        $stoneDisponivel = static function () use (&$stoneDisponivelEmCache): bool {
            return $stoneDisponivelEmCache ??= Maquininha::stoneDisponivel()->exists();
        };

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'cliente',
                    'opcaoEntrega',
                    'sessaoMesa.mesa',
                    'garcom:id,name_first',
                    'entregador:id,name_first',
                    'pagamentosCombinados.opcaoPagamento',
                    'stonePedidosPendentes',
                ])
                ->withExists(['item_pedido_pedido_id as tem_item_vendido' => fn (Builder $item) => $item->whereNotNull('item_pedido_venda_id')]))
            ->description(fn ($livewire): ?string => method_exists($livewire, 'janelaPadraoAtiva') && $livewire->janelaPadraoAtiva()
                ? 'Mostrando os últimos '.ListPedidos::PERIODO_PADRAO_DIAS.' dias. Para ver outros dias, ajuste o período em Filtros ou busque pelo número.'
                : null)
            ->defaultSort(fn (Builder $query, $livewire): Builder => in_array($livewire->activeTab ?? 'todos', self::ABAS_SEM_DATA, true)
                ? $query->orderByDesc('pedidos.id')
                : $query->orderByRaw('COALESCE(pedidos.pedido_datahora_abertura, pedidos.created_at) desc')->orderByDesc('pedidos.id'))
            ->recordUrl(fn (Pedido $record): string => ViewPedido::getUrl(['record' => $record]))
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->numeric()
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => is_numeric($search)
                        ? $query->where('pedidos.id', (int) $search)
                        : $query->whereRaw('1 = 0')),
                TextColumn::make('abertura')
                    ->label('Abertura')
                    ->state(fn (Pedido $record): ?Carbon => $record->pedido_datahora_abertura ?? $record->created_at)
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (Pedido $record): ?string => ($record->pedido_datahora_abertura ?? $record->created_at)?->diffForHumans())
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw('COALESCE(pedidos.pedido_datahora_abertura, pedidos.created_at) '.($direction === 'asc' ? 'asc' : 'desc'))),
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->state(fn (Pedido $record): string => $record->tipoAtendimento()->label())
                    ->color(fn (string $state): string => match ($state) {
                        TipoAtendimentoEnum::MESA->label() => 'warning',
                        TipoAtendimentoEnum::DELIVERY->label() => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('cliente')
                    ->label('Cliente')
                    ->state(fn (Pedido $record): string => $record->pedido_cliente_id ? $record->cliente->cliente_nome : 'Não informado')
                    ->description(fn (Pedido $record): ?string => $record->pedido_cliente_id ? $record->cliente->cliente_celular : null)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'cliente',
                        fn (Builder $cliente) => $cliente
                            ->where('cliente_nome', 'like', "%{$search}%")
                            ->orWhere('cliente_celular', 'like', "%{$search}%"),
                    )),
                TextColumn::make('pedido_valor_total')
                    ->label('Valor do pedido')
                    ->money('BRL')
                    ->sortable()
                    ->summarize(Sum::make('sum')
                        // Rótulo curto de propósito: o do rodapé não quebra linha e alargaria a coluna, empurrando as outras para fora da tela.
                        ->label('Total (exceto cancelados)')
                        ->money('BRL')
                        ->query(fn (QueryBuilder $query): QueryBuilder => $query->where('pedidos.pedido_status', '!=', StatusPedidoEnum::CANCELADO->value))),
                TextColumn::make('pedido_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StatusPedidoEnum::tryFrom($state)?->label() ?? $state)
                    ->icon(fn (string $state) => StatusPedidoEnum::tryFrom($state)?->icone())
                    ->color(fn (string $state): string => StatusPedidoEnum::tryFrom($state)?->cor() ?? 'gray'),
                TextColumn::make('pagamento')
                    ->label('Pagamento')
                    ->badge()
                    ->state(fn (Pedido $record): ?string => $record->situacaoPagamento()?->value)
                    ->formatStateUsing(fn (string $state): string => SituacaoPagamentoPedidoEnum::from($state)->label())
                    ->placeholder('—')
                    ->color(fn (?string $state, Pedido $record): string => match ($state) {
                        SituacaoPagamentoPedidoEnum::PAGO->value => 'success',
                        SituacaoPagamentoPedidoEnum::PARCIAL->value => 'warning',
                        // Entregue e sem receber é a pendência de verdade; os demais ainda estão a caminho.
                        SituacaoPagamentoPedidoEnum::A_RECEBER->value => $record->pedido_status === StatusPedidoEnum::ENTREGUE->value ? 'danger' : 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('sla')
                    ->label('SLA')
                    ->badge()
                    ->state(fn (Pedido $record): ?string => ($status = $record->status()) ? UrgenciaPedidoEnum::paraPedido($record, $status)->value : null)
                    ->formatStateUsing(fn (string $state): string => UrgenciaPedidoEnum::from($state)->label())
                    ->color(fn (string $state): string => UrgenciaPedidoEnum::from($state)->cor())
                    ->description(fn (Pedido $record): ?string => ($status = $record->status()) && ($minutos = UrgenciaPedidoEnum::minutosNoStatus($record, $status))
                        ? "{$minutos} min neste status"
                        : null)
                    ->visible(fn ($livewire): bool => ($livewire->activeTab ?? null) === 'andamento'),
                // As relações do model têm withDefault() ("S/G", "S/E", "S/M", "S/OPE")
                // de que as views legadas dependem: a lista resolve o vazio aqui.
                TextColumn::make('garcom')
                    ->label('Atendente')
                    ->state(fn (Pedido $record): ?string => $record->pedido_usuario_garcom_id ? $record->garcom->name_first : null)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('entregador')
                    ->label('Entregador')
                    ->state(fn (Pedido $record): ?string => $record->pedido_usuario_entrega_id ? $record->entregador->name_first : null)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('mesa')
                    ->label('Mesa')
                    ->state(fn (Pedido $record): ?string => $record->pedido_sessao_mesa_id ? $record->sessaoMesa->mesa?->mesa_nome : null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('entrega')
                    ->label('Opção de entrega')
                    ->state(fn (Pedido $record): ?string => $record->pedido_opcaoentrega_id ? $record->opcaoEntrega->opcaoentrega_nome : null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pedido_origem')
                    ->label('Origem')
                    ->formatStateUsing(fn ($state): string => $state instanceof PedidoOrigemEnum ? $state->label() : (string) $state)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters(PedidosFiltros::filtros(), layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormSchema(PedidosFiltros::secoes(...))
            ->deferFilters(false)
            ->persistFiltersInSession()
            // Antes das células: com tantas colunas, o menu de ações ficaria fora da tela à direita.
            ->recordActionsPosition(RecordActionsPosition::BeforeCells)
            ->recordActions([
                ActionGroup::make([
                    Action::make('atender')
                        ->label('Atender')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (Pedido $record): bool => ! $record->trashed())
                        ->url(fn (Pedido $record): string => AtenderPedido::getUrl(['pedido' => $record])),

                    PedidoStatusActions::copiarLinkAcompanhamento(),
                    PedidoStatusActions::cancelarDaTabela(),
                    PedidoStatusActions::cancelarEntregueDaTabela(),
                    PedidoExclusaoActions::excluir(),
                    PedidoExclusaoActions::restaurar(),
                    self::enviarStoneAction($stoneDisponivel),
                    self::cancelarStoneAction(),
                ])
                    ->label('Ações')
                    ->icon(Heroicon::EllipsisVertical)
                    ->tooltip('Ações do pedido')
                    ->color('gray'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(PedidoExporter::class)
                        ->authorize('export', Pedido::class),
                    PedidoExclusaoActions::excluirEmLote(),
                    PedidoExclusaoActions::restaurarEmLote(),
                    self::enviarStoneBulkAction($stoneDisponivel),
                    self::cancelarStoneBulkAction(),
                ]),
            ]);
    }

    /**
     * @return array<int, Select>
     */
    private static function schemaCobrancaStone(): array
    {
        return [
            Select::make('maquininha_id')
                ->label('Maquininha')
                ->options(fn () => Maquininha::stoneDisponivel()->orderBy('nome')->pluck('nome', 'id'))
                ->required(),

            Select::make('opcaopagamento_id')
                ->label('Forma de pagamento (opcional)')
                ->options(fn () => OpcoesPagamento::where('opcaopag_stone_integrada', true)->orderBy('opcaopag_nome')->pluck('opcaopag_nome', 'id'))
                ->helperText('Sem escolher, o tipo (crédito/débito/PIX) é selecionado na própria maquininha.'),
        ];
    }

    /**
     * @param  array{maquininha_id: int, opcaopagamento_id?: ?int}  $data
     */
    private static function enviarCobranca(Pedido $pedido, array $data): void
    {
        $maquininha = Maquininha::findOrFail($data['maquininha_id']);
        $opcao = filled($data['opcaopagamento_id'] ?? null) ? OpcoesPagamento::find($data['opcaopagamento_id']) : null;
        $modo = $opcao ? StonePedidoModo::Direto : StonePedidoModo::Listado;

        app(StoneRecebimentoService::class)->iniciarCobrancaDePedido(
            $pedido, $opcao, $maquininha, (float) $pedido->pedido_valor_total, $modo,
        );
    }

    private static function enviarStoneAction(Closure $stoneDisponivel): Action
    {
        return Action::make('enviarStone')
            ->label('Enviar para a maquininha')
            ->icon('heroicon-o-credit-card')
            ->color('primary')
            ->visible(fn (Pedido $record): bool => self::podeEnviarStone($record, $stoneDisponivel))
            ->schema(self::schemaCobrancaStone())
            ->action(function (Pedido $record, array $data): void {
                try {
                    self::enviarCobranca($record, $data);
                    Notification::make()->success()->title('Pedido enviado para a maquininha.')->send();
                } catch (StoneConnectException $e) {
                    Notification::make()->danger()->title('Falha ao enviar')->body($e->getMessage())->send();
                }
            });
    }

    private static function enviarStoneBulkAction(Closure $stoneDisponivel): BulkAction
    {
        return BulkAction::make('enviarStoneBulk')
            ->label('Enviar para a maquininha')
            ->icon('heroicon-o-credit-card')
            ->schema(self::schemaCobrancaStone())
            ->action(function (Collection $records, array $data) use ($stoneDisponivel): void {
                $enviados = 0;
                $ignorados = 0;

                foreach ($records as $pedido) {
                    if (! self::podeEnviarStone($pedido, $stoneDisponivel)) {
                        $ignorados++;

                        continue;
                    }

                    try {
                        self::enviarCobranca($pedido, $data);
                        $enviados++;
                    } catch (StoneConnectException) {
                        $ignorados++;
                    }
                }

                Notification::make()
                    ->title($ignorados > 0
                        ? "{$enviados} pedido(s) enviado(s), {$ignorados} ignorado(s)."
                        : "{$enviados} pedido(s) enviado(s) para a maquininha.")
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function cancelarStoneAction(): Action
    {
        return Action::make('cancelarStone')
            ->label('Cancelar cobrança na maquininha')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Pedido $record): bool => self::stonePedidoPendente($record) !== null)
            ->action(function (Pedido $record): void {
                $stonePedido = self::stonePedidoPendente($record);
                if (! $stonePedido) {
                    return;
                }

                app(StoneRecebimentoService::class)->cancelarCobranca($stonePedido);
                Notification::make()->success()->title('Cobrança cancelada na maquininha.')->send();
            });
    }

    private static function cancelarStoneBulkAction(): BulkAction
    {
        return BulkAction::make('cancelarStoneBulk')
            ->label('Cancelar cobrança na maquininha')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (Collection $records): void {
                $cancelados = 0;

                foreach ($records as $pedido) {
                    $stonePedido = self::stonePedidoPendente($pedido);
                    if (! $stonePedido) {
                        continue;
                    }

                    app(StoneRecebimentoService::class)->cancelarCobranca($stonePedido);
                    $cancelados++;
                }

                Notification::make()->success()->title("{$cancelados} cobrança(s) cancelada(s) na maquininha.")->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /** A lista já traz as cobranças pendentes carregadas (stonePedidosPendentes); em lote, a relação carrega sob demanda. */
    private static function stonePedidoPendente(Pedido $record): ?StonePedido
    {
        return $record->stonePedidosPendentes->first();
    }

    /** As checagens baratas vêm antes; a consulta à maquininha só roda se todas passarem. */
    private static function podeEnviarStone(Pedido $record, Closure $stoneDisponivel): bool
    {
        return ! in_array($record->pedido_status, ['CANCELADO', 'FINALIZADO'], true)
            && blank($record->pedido_venda_id)
            && self::stonePedidoPendente($record) === null
            && $record->temPagamentoCombinadoStone()
            && $stoneDisponivel();
    }
}
