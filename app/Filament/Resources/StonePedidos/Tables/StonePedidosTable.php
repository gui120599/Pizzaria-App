<?php

namespace App\Filament\Resources\StonePedidos\Tables;

use App\Enums\StonePedidoOrigem;
use App\Enums\StonePedidoStatus;
use App\Exceptions\StoneConnectException;
use App\Models\SessaoCaixa;
use App\Models\StonePedido;
use App\Services\Stone\StoneConnectService;
use App\Services\Stone\StoneRecebimentoService;
use App\Services\Stone\StoneVendaAutomaticaService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StonePedidosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),

                TextColumn::make('stp_status')
                    ->label('Status')
                    ->badge(),

                TextColumn::make('stp_modo')
                    ->label('Modo')
                    ->badge()
                    ->toggleable(),

                TextColumn::make('stp_origem')
                    ->label('Origem')
                    ->badge()
                    ->toggleable(),

                TextColumn::make('alvo')
                    ->label('Pedido/Mesa')
                    ->state(function (StonePedido $record): string {
                        return match (true) {
                            filled($record->stp_sessao_mesa_id) => 'Mesa #'.$record->stp_sessao_mesa_id,
                            filled($record->stp_pedido_id) => 'Pedido #'.$record->stp_pedido_id,
                            default => '—',
                        };
                    })
                    ->toggleable(),

                TextColumn::make('stp_venda_id')
                    ->label('Venda')
                    ->formatStateUsing(fn (?int $state): string => $state ? "#{$state}" : '—'),

                TextColumn::make('maquininha.nome')
                    ->label('Maquininha')
                    ->default('—'),

                TextColumn::make('stp_valor_solicitado')
                    ->label('Solicitado')
                    ->money('BRL'),

                TextColumn::make('stp_valor_pago')
                    ->label('Pago')
                    ->money('BRL'),

                TextColumn::make('stp_order_code')
                    ->label('Pedido')
                    ->fontFamily('mono')
                    ->copyable()
                    ->default('—')
                    ->searchable(),

                TextColumn::make('stp_charge_code')
                    ->label('NSU')
                    ->fontFamily('mono')
                    ->default('—')
                    ->searchable(),

                TextColumn::make('stp_order_id')
                    ->label('Order ID')
                    ->fontFamily('mono')
                    ->copyable()
                    ->default('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('stp_charge_id')
                    ->label('Charge ID')
                    ->fontFamily('mono')
                    ->copyable()
                    ->default('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('stp_fechado_em')
                    ->label('Fechado na Stone')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),

                TextColumn::make('stp_erro')
                    ->label('Erro')
                    ->color('danger')
                    ->limit(60)
                    ->toggleable()
                    ->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('stp_status')
                    ->label('Status')
                    ->options(collect(StonePedidoStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])),

                Filter::make('nao_fechados')
                    ->label('Pagos ainda não fechados na Stone')
                    ->query(fn (Builder $query) => $query->where('stp_status', StonePedidoStatus::Pago->value)->whereNull('stp_fechado_em')),

                Filter::make('abertas_na_maquininha')
                    ->label('Abertas na maquininha (sem pagamento)')
                    ->query(fn (Builder $query) => $query
                        ->whereIn('stp_status', [StonePedidoStatus::Aguardando->value, StonePedidoStatus::Indeterminado->value, StonePedidoStatus::Falha->value])
                        ->whereNotNull('stp_order_id')
                        ->whereNull('stp_fechado_em')),

                Filter::make('pagos_sem_venda')
                    ->label('Pagos sem venda')
                    ->query(fn (Builder $query) => $query->whereIn('stp_status', [StonePedidoStatus::Pago->value, StonePedidoStatus::PagoParcial->value])
                        ->whereNull('stp_venda_id')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('cancelarPedidosStone')
                        ->label('Cancelar na Stone')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Cancelar na Stone')
                        ->modalDescription('Retira da lista da maquininha os pedidos selecionados que ainda estão abertos. Os demais são ignorados.')
                        ->action(function (Collection $records): void {
                            $resultado = app(StoneRecebimentoService::class)->cancelarCobrancasNaStone($records);

                            Notification::make()
                                ->title("{$resultado['cancelados']} pedido(s) cancelado(s) na Stone.")
                                ->body($resultado['recusados'] === [] ? null : implode("\n", $resultado['recusados']))
                                ->color($resultado['recusados'] === [] ? 'success' : 'warning')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->recordActions([
                Action::make('fecharPedido')
                    ->label('Fechar na Stone')
                    ->icon('heroicon-o-lock-closed')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (StonePedido $record): bool => $record->stp_status === StonePedidoStatus::Pago && $record->stp_fechado_em === null && filled($record->stp_order_id))
                    ->action(function (StonePedido $record): void {
                        try {
                            app(StoneConnectService::class)->fecharPedido($record->stp_order_id, 'paid');
                            $record->update(['stp_fechado_em' => now()]);
                            Notification::make()->success()->title('Pedido fechado na Stone.')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Falha ao fechar o pedido')->body($e->getMessage())->send();
                        }
                    }),

                Action::make('cancelarPedido')
                    ->label('Cancelar na Stone')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cancelar na Stone')
                    ->modalDescription('Retira o pedido da lista da maquininha. Use quando o cliente pagou de outra forma (ex.: trocou o cartão por dinheiro na entrega).')
                    ->visible(fn (StonePedido $record): bool => app(StoneRecebimentoService::class)->podeCancelarNaStone($record))
                    ->action(function (StonePedido $record): void {
                        try {
                            app(StoneRecebimentoService::class)->cancelarNaStone($record);
                            Notification::make()->success()->title('Pedido cancelado na Stone.')->send();
                        } catch (StoneConnectException $e) {
                            Notification::make()->danger()->title('Falha ao cancelar o pedido')->body($e->getMessage())->send();
                        }
                    }),

                // Recuperação manual pro caso do R6: o pagamento chegou mas a
                // Venda não pôde ser criada sozinha (ver stp_erro) — o back
                // office escolhe a sessão de caixa e o sistema tenta de novo.
                Action::make('gerarVenda')
                    ->label('Gerar venda')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->visible(fn (StonePedido $record): bool => $record->stp_origem !== StonePedidoOrigem::Venda
                        && blank($record->stp_venda_id)
                        && in_array($record->stp_status, [StonePedidoStatus::Pago, StonePedidoStatus::PagoParcial], true))
                    ->schema([
                        Select::make('sessaocaixa_id')
                            ->label('Sessão de caixa')
                            ->options(fn () => SessaoCaixa::where('sessaocaixa_status', 'ABERTA')->pluck('id', 'id'))
                            ->required()
                            ->helperText('Só sessões ABERTA no momento aparecem aqui.'),
                    ])
                    ->action(function (StonePedido $record, array $data): void {
                        app(StoneVendaAutomaticaService::class)->recuperarVendaEPagamento($record, (int) $data['sessaocaixa_id']);

                        $record = $record->fresh();
                        if (blank($record->stp_venda_id)) {
                            Notification::make()->danger()->title('Não foi possível gerar a venda')->body($record->stp_erro)->send();

                            return;
                        }

                        Notification::make()->success()->title("Venda #{$record->stp_venda_id} gerada.")->send();
                    }),
            ]);
    }
}
