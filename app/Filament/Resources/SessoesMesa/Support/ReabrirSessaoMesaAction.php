<?php

namespace App\Filament\Resources\SessoesMesa\Support;

use App\Models\SessaoMesa;
use App\Services\SessaoMesaService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use RuntimeException;

/**
 * Mesma regra de SessaoMesaController::ReabrirSessaoMesa (via SessaoMesaService):
 * bloqueia se a mesa já foi reocupada por outra sessão.
 */
class ReabrirSessaoMesaAction
{
    public static function make(string $name = 'reabrirSessaoMesa'): Action
    {
        return Action::make($name)
            ->label('Reabrir sessão')
            ->icon('heroicon-o-lock-open')
            ->color('gray')
            ->visible(fn (SessaoMesa $record): bool => $record->sessao_mesa_status === 'FECHADA'
                && auth()->user()?->can('update', $record))
            ->requiresConfirmation()
            ->modalHeading('Reabrir sessão da mesa')
            ->action(function (SessaoMesa $record, SessaoMesaService $service): void {
                try {
                    $service->reabrir($record);
                } catch (RuntimeException $e) {
                    Notification::make()
                        ->title($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Sessão reaberta com sucesso!')
                    ->success()
                    ->send();
            });
    }
}
