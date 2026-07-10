<?php

namespace App\Filament\Resources\Compras\Support;

use App\Enums\FormaPagamento;
use App\Models\Compra;
use App\Services\CompraService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ação de confirmar compra, reutilizada na tabela e na página de edição.
 *
 * Ao confirmar, opcionalmente gera a conta a pagar (com o vencimento informado no
 * modal), tudo na mesma transação: estoque + título nascem juntos ou nada acontece.
 */
class ConfirmarCompraAction
{
    public static function make(string $name = 'confirmar'): Action
    {
        return Action::make($name)
            ->label('Confirmar compra')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Compra $record): bool => $record->isRascunho())
            ->modalHeading('Confirmar compra')
            ->modalDescription('Gera as entradas de estoque e recalcula o custo médio. Esta ação não pode ser desfeita.')
            ->modalSubmitActionLabel('Confirmar')
            ->schema([
                Toggle::make('gerar_conta_pagar')
                    ->label('Gerar conta a pagar')
                    ->helperText('Cria um título a pagar para o fornecedor, rateado pelos planos de despesa dos produtos.')
                    ->default(true)
                    ->live(),
                DatePicker::make('vencimento')
                    ->label('Vencimento')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->default(fn (Compra $record) => $record->compra_data_entrada ?? now())
                    ->required(fn (Get $get): bool => (bool) $get('gerar_conta_pagar'))
                    ->visible(fn (Get $get): bool => (bool) $get('gerar_conta_pagar')),
                Select::make('forma_pagamento')
                    ->label('Forma de pagamento')
                    ->options(FormaPagamento::class)
                    ->visible(fn (Get $get): bool => (bool) $get('gerar_conta_pagar')),
            ])
            ->action(function (Compra $record, array $data, CompraService $service, Action $action): void {
                $gerar = (bool) ($data['gerar_conta_pagar'] ?? false);

                try {
                    DB::transaction(function () use ($record, $data, $service, $gerar): void {
                        $service->confirmar($record);

                        if ($gerar) {
                            $service->gerarContaPagar($record, [
                                'vencimento' => $data['vencimento'] ?? null,
                                'forma_pagamento' => $data['forma_pagamento'] ?? null,
                            ]);
                        }
                    });
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Não foi possível confirmar')
                        ->body(collect($e->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    // Interrompe o ciclo da ação (não dispara redirect/after em caso de falha).
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title('Compra confirmada')
                    ->body($gerar ? 'Estoque atualizado e conta a pagar gerada.' : 'Estoque atualizado.')
                    ->success()
                    ->send();
            });
    }
}
