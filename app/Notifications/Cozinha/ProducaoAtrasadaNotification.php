<?php

namespace App\Notifications\Cozinha;

use App\Models\ProducaoItem;
use App\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/** Gerente: item passou de N × o tempo-alvo do setor (cozinha:alertar-atrasos). */
class ProducaoAtrasadaNotification extends Notification
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
        $minutos = intdiv($this->item->idadeSegundos(), 60);

        return FilamentNotification::make()
            ->title('Item atrasado na cozinha')
            ->body("{$this->item->snapshot['produto']} — {$this->item->referencia} ({$this->item->setor?->nome}): {$minutos} min.")
            ->icon(Heroicon::OutlinedClock)
            ->iconColor('danger')
            ->getDatabaseMessage();
    }
}
