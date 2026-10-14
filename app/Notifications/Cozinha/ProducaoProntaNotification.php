<?php

namespace App\Notifications\Cozinha;

use App\Models\Pedido;
use App\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/** Garçom: os itens da mesa ficaram prontos (ou foram despachados, com Expedição). */
class ProducaoProntaNotification extends Notification
{
    public function __construct(public readonly Pedido $pedido) {}

    /** @return array<int, string> */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        $this->pedido->loadMissing('sessaoMesa.mesa');
        $mesa = $this->pedido->sessaoMesa?->mesa?->mesa_nome;

        return FilamentNotification::make()
            ->title($mesa ? "Mesa {$mesa}: pedido pronto" : 'Pedido pronto')
            ->body("Pedido #{$this->pedido->id} pronto para levar.")
            ->icon(Heroicon::OutlinedBellAlert)
            ->iconColor('success')
            ->getDatabaseMessage();
    }
}
