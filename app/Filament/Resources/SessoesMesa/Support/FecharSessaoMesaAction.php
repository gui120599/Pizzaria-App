<?php

namespace App\Filament\Resources\SessoesMesa\Support;

use App\Models\SessaoMesa;
use App\Services\SessaoMesaService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Mesma regra de SessaoMesaController::FecharSessaoMesa (via SessaoMesaService):
 * com pedidos ativos vira FECHADA, sem nenhum vira CANCELADA — em ambos os casos
 * a mesa é liberada.
 */
class FecharSessaoMesaAction
{
    public static function make(string $name = 'fecharSessaoMesa'): Action
    {
        return Action::make($name)
            ->label('Fechar sessão')
            ->icon('heroicon-o-lock-closed')
            ->color('warning')
            ->visible(fn (SessaoMesa $record): bool => $record->sessao_mesa_status === 'ABERTA'
                && auth()->user()?->can('update', $record))
            ->requiresConfirmation()
            ->modalHeading('Fechar sessão da mesa')
            ->modalDescription('Libera a mesa pra outros clientes. Se não houver pedidos ativos, a sessão é cancelada.')
            ->action(function (SessaoMesa $record, SessaoMesaService $service): void {
                $service->fechar($record);

                Notification::make()
                    ->title('Sessão fechada com sucesso!')
                    ->success()
                    ->send();
            });
    }
}
