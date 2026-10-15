<?php

namespace App\Notifications\Ponto;

use App\Models\PontoAjuste;
use App\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/** Aos gestores: um colaborador pediu ajuste de ponto. */
class AjusteSolicitadoNotification extends Notification
{
    public function __construct(public readonly PontoAjuste $ajuste) {}

    /** @return array<int, string> */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        return [
            ...FilamentNotification::make()
                ->title('Ajuste de ponto solicitado')
                ->body("{$this->ajuste->colaborador->nome} pediu {$this->ajuste->tipo->getLabel()} em {$this->ajuste->data_referencia->format('d/m/Y')} ({$this->ajuste->motivo->getLabel()}).")
                ->icon(Heroicon::OutlinedClock)
                ->iconColor('warning')
                ->getDatabaseMessage(),
            'ponto_ajuste_id' => $this->ajuste->id,
        ];
    }
}
