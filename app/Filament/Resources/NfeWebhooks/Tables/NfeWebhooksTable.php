<?php

namespace App\Filament\Resources\NfeWebhooks\Tables;

use App\Models\NfeWebhook;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class NfeWebhooksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Recebido em')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),

                TextColumn::make('nfw_evento')
                    ->label('Evento')
                    ->default('—')
                    ->searchable(),

                TextColumn::make('nfw_invoice_id')
                    ->label('Invoice ID')
                    ->copyable()
                    ->fontFamily('mono')
                    ->default('—')
                    ->searchable(),

                TextColumn::make('venda.id')
                    ->label('Venda')
                    ->formatStateUsing(fn (?string $state): string => $state ? "#{$state}" : '—'),

                TextColumn::make('nfw_assinatura_valida')
                    ->label('Assinatura')
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
                    ->modalHeading(fn (NfeWebhook $record): string => 'Payload — '.($record->nfw_invoice_id ?? "#{$record->id}"))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalContent(fn (NfeWebhook $record): HtmlString => new HtmlString(
                        '<pre class="text-xs whitespace-pre-wrap break-all bg-gray-50 dark:bg-white/5 rounded-lg p-3">'
                        .e(json_encode($record->nfw_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
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
