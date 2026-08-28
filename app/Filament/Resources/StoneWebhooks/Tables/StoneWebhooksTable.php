<?php

namespace App\Filament\Resources\StoneWebhooks\Tables;

use App\Models\StoneWebhook;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class StoneWebhooksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Recebido em')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),

                TextColumn::make('stw_evento')
                    ->label('Evento')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'charge.paid' => 'success',
                        'charge.refunded' => 'danger',
                        default => 'gray',
                    })
                    ->default('—')
                    ->searchable(),

                TextColumn::make('stw_order_code')
                    ->label('Pedido')
                    ->copyable()
                    ->fontFamily('mono')
                    ->default('—')
                    ->searchable(),

                TextColumn::make('stw_charge_code')
                    ->label('Transação')
                    ->fontFamily('mono')
                    ->default('—')
                    ->searchable(),

                TextColumn::make('venda.id')
                    ->label('Venda')
                    ->formatStateUsing(fn (?string $state): string => $state ? "#{$state}" : '—'),

                TextColumn::make('stw_autenticado')
                    ->label('Autenticação')
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => match ($state) {
                        true => 'Válida',
                        false => 'Inválida',
                        null => 'Não verificada',
                    })
                    ->color(fn (?bool $state): string => match ($state) {
                        true => 'success',
                        false => 'danger',
                        null => 'gray',
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('verPayload')
                    ->label('Ver payload')
                    ->icon('heroicon-o-code-bracket')
                    ->color('gray')
                    ->modalHeading(fn (StoneWebhook $record): string => 'Payload — '.($record->stw_order_code ?? "#{$record->id}"))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalContent(fn (StoneWebhook $record): HtmlString => new HtmlString(
                        '<pre class="text-xs whitespace-pre-wrap break-all bg-gray-50 dark:bg-white/5 rounded-lg p-3">'
                        .e(json_encode($record->stw_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                        .'</pre>'
                    )),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
