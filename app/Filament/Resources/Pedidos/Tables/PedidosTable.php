<?php

namespace App\Filament\Resources\Pedidos\Tables;

use App\Enums\StatusPedidoEnum;
use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoStatus;
use App\Exceptions\StoneConnectException;
use App\Filament\Pages\AtenderPedido;
use App\Filament\Support\PedidoStatusActions;
use App\Models\Maquininha;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\StonePedido;
use App\Services\Stone\StoneRecebimentoService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class PedidosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (Pedido $record): string => AtenderPedido::getUrl(['pedido' => $record]))
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('cliente.cliente_nome')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sessaoMesa.mesa.mesa_nome')
                    ->label('Mesa')
                    ->sortable(),
                TextColumn::make('garcom.name')
                    ->label('Atendente')
                    ->sortable(),
                TextColumn::make('entregador.name')
                    ->label('Entregador')
                    ->sortable(),
                TextColumn::make('opcaoEntrega.opcaoentrega_nome')
                    ->label('Entrega')
                    ->sortable(),
                TextColumn::make('pedido_valor_total')
                    ->label('Total')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('pedido_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => StatusPedidoEnum::tryFrom($state)?->cor() ?? 'gray'),
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
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('atender')
                    ->label('Atender')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (Pedido $record): string => AtenderPedido::getUrl(['pedido' => $record])),

                PedidoStatusActions::copiarLinkAcompanhamento(),
                PedidoStatusActions::cancelarDaTabela(),
                self::enviarStoneAction(),
                self::cancelarStoneAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    self::enviarStoneBulkAction(),
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

    private static function enviarStoneAction(): Action
    {
        return Action::make('enviarStone')
            ->label('Enviar para a maquininha')
            ->icon('heroicon-o-credit-card')
            ->color('primary')
            ->visible(fn (Pedido $record): bool => self::podeEnviarStone($record))
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

    private static function enviarStoneBulkAction(): BulkAction
    {
        return BulkAction::make('enviarStoneBulk')
            ->label('Enviar para a maquininha')
            ->icon('heroicon-o-credit-card')
            ->schema(self::schemaCobrancaStone())
            ->action(function (Collection $records, array $data): void {
                $enviados = 0;
                $ignorados = 0;

                foreach ($records as $pedido) {
                    if (! self::podeEnviarStone($pedido)) {
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

    private static function stonePedidoPendente(Pedido $record): ?StonePedido
    {
        return StonePedido::where('stp_pedido_id', $record->id)
            ->whereIn('stp_status', [StonePedidoStatus::Aguardando->value, StonePedidoStatus::PagoParcial->value])
            ->latest()
            ->first();
    }

    private static function podeEnviarStone(Pedido $record): bool
    {
        return ! in_array($record->pedido_status, ['CANCELADO', 'FINALIZADO'], true)
            && blank($record->pedido_venda_id)
            && self::stonePedidoPendente($record) === null
            && Maquininha::stoneDisponivel()->exists()
            && $record->temPagamentoCombinadoStone();
    }
}
