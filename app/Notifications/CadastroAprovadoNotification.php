<?php

namespace App\Notifications;

use App\Models\Influenciador;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class CadastroAprovadoNotification extends Notification
{
    /** @return array<int, string> */
    public function via(Influenciador $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(Influenciador $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Cadastro aprovado')
            ->body('Seu cadastro de influenciador foi aprovado. Crie seus cupons em "Meus Cupons".')
            ->success()
            ->getDatabaseMessage();
    }
}
