<?php

namespace App\Filament\Resources\SessoesCaixa\Support;

use App\Models\SessaoCaixa;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Mesma regra de SessaoCaixaController::finalizar — só quem abriu a sessão pode
 * fechá-la (SessaoCaixaPolicy::close), Admin passa por tudo via Gate::before.
 */
class FinalizarSessaoCaixaAction
{
    public static function make(string $name = 'finalizarSessaoCaixa'): Action
    {
        return Action::make($name)
            ->label('Finalizar sessão')
            ->icon('heroicon-o-lock-closed')
            ->color('danger')
            ->visible(fn (SessaoCaixa $record): bool => $record->sessaocaixa_status === 'ABERTA'
                && auth()->user()?->can('close', $record))
            ->requiresConfirmation()
            ->modalHeading('Finalizar sessão de caixa')
            ->modalDescription('Encerra o turno deste caixa. Não é possível reabrir depois.')
            ->action(function (SessaoCaixa $record): void {
                $record->update([
                    'sessaocaixa_status' => 'FECHADA',
                    'sessaocaixa_data_hora_fechamento' => now(),
                ]);

                Notification::make()
                    ->title('Sessão finalizada com sucesso!')
                    ->success()
                    ->send();
            });
    }
}
