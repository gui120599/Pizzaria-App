<?php

namespace App\Filament\Resources\ContasFinanceiras\Support;

use App\Exceptions\FinanceiroException;
use App\Models\ContaFinanceira;
use App\Services\Financeiro\MovimentacaoService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Leandrocfe\FilamentPtbrFormFields\Money;

/** Corrige o saldo para o valor conferido, com uma movimentação da diferença (só Admin; não vale para o Caixa PDV). */
class AjustarSaldoAction
{
    public static function make(string $name = 'ajustarSaldo'): Action
    {
        return Action::make($name)
            ->label('Ajustar saldo')
            ->icon(Heroicon::OutlinedScale)
            ->color('warning')
            ->authorize('ajustarSaldo')
            ->visible(fn (ContaFinanceira $record): bool => ! $record->ehCaixaPdv() && ! $record->trashed())
            ->modalHeading(fn (ContaFinanceira $record): string => "Ajustar saldo — {$record->nome}")
            ->modalDescription(fn (ContaFinanceira $record): string => 'Saldo atual: '.ValorMonetario::formatar((float) $record->saldo_atual).'. Informe o saldo conferido.')
            ->schema([
                Money::make('novo_saldo')
                    ->label('Saldo conferido')
                    ->required(),
                Textarea::make('motivo')
                    ->label('Motivo')
                    ->required()
                    ->minLength(5)
                    ->rows(2),
            ])
            ->action(function (ContaFinanceira $record, array $data, MovimentacaoService $movimentacoes, Action $action): void {
                try {
                    $movimentacoes->ajustarSaldo($record, ValorMonetario::normalizar($data['novo_saldo'] ?? null), $data['motivo'], auth()->user());
                } catch (FinanceiroException $e) {
                    Notification::make()->title('Saldo não ajustado')->body($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Saldo ajustado')->success()->send();
            });
    }
}
