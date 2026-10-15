<?php

namespace App\Filament\Resources\MovimentacoesFinanceiras\Support;

use App\Exceptions\FinanceiroException;
use App\Models\MovimentacaoFinanceira;
use App\Services\Financeiro\MovimentacaoService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Movimentação inversa ligada à original. Baixa de lançamento: o pagamento é
 * excluído e o título reabre. Transferência: estorna as duas pontas.
 */
class EstornarMovimentacaoAction
{
    public static function make(string $name = 'estornar'): Action
    {
        return Action::make($name)
            ->label('Estornar')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->authorize('estornar')
            ->visible(fn (MovimentacaoFinanceira $record): bool => $record->estorno_de_id === null && ! $record->estorno()->exists())
            ->modalHeading('Estornar movimentação')
            ->modalDescription(fn (MovimentacaoFinanceira $record): string => match (true) {
                $record->lancamento_pagamento_id !== null => 'O pagamento do lançamento é excluído e o título volta a ficar em aberto.',
                $record->transferencia_uuid !== null => 'As duas pontas da transferência são estornadas.',
                default => 'Cria uma movimentação inversa; a original fica no extrato.',
            })
            ->modalSubmitActionLabel('Estornar')
            ->schema([
                Textarea::make('motivo')
                    ->label('Motivo')
                    ->required()
                    ->minLength(5)
                    ->rows(2),
            ])
            ->action(function (MovimentacaoFinanceira $record, array $data, MovimentacaoService $movimentacoes, Action $action): void {
                try {
                    $movimentacoes->estornar($record, $data['motivo'], auth()->user());
                } catch (FinanceiroException $e) {
                    Notification::make()->title('Estorno não feito')->body($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Movimentação estornada')->success()->send();
            });
    }
}
