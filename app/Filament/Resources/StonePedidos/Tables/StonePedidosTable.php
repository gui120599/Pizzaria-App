<?php

namespace App\Filament\Resources\StonePedidos\Tables;

use App\Enums\StonePedidoStatus;
use App\Models\StonePedido;
use App\Services\Stone\StoneConnectService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

                TextColumn::make('stp_fechado_em')
                    ->label('Fechado na Stone')
                    ->dateTime('d/m/Y H:i')
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
                    ->visible(fn (StonePedido $record): bool => $record->stp_status === StonePedidoStatus::Aguardando && filled($record->stp_order_id))
                    ->action(function (StonePedido $record): void {
                        try {
                            app(StoneConnectService::class)->cancelarPedido($record->stp_order_id);
                            $record->update(['stp_status' => StonePedidoStatus::Cancelado]);
                            Notification::make()->success()->title('Pedido cancelado na Stone.')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Falha ao cancelar o pedido')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }
}
