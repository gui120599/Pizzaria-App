<?php

namespace App\Notifications\Cozinha;

use App\Models\ProducaoItem;
use App\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/** Gerente: um item já em preparo (ou pronto) foi cancelado — possível desperdício. */
class ItemCanceladoEmPreparoNotification extends Notification
{
    public function __construct(public readonly ProducaoItem $item) {}

    /** @return array<int, string> */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Item cancelado durante o preparo')
            ->body("{$this->item->snapshot['produto']} — {$this->item->referencia}: {$this->item->motivo_cancelamento?->getLabel()}.")
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->iconColor('warning')
            ->getDatabaseMessage();
    }
}
