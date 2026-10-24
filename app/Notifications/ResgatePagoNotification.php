<?php

namespace App\Notifications;

use App\Models\Influenciador;
use App\Models\ResgateCarteira;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class ResgatePagoNotification extends Notification
{
    public function __construct(private ResgateCarteira $resgate) {}

    /** @return array<int, string> */
    public function via(Influenciador $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(Influenciador $notifiable): array
    {
        $valor = number_format((float) $this->resgate->rc_valor, 2, ',', '.');

        return FilamentNotification::make()
            ->title('Resgate em Pix pago')
            ->body("R$ {$valor} foram enviados por Pix para a sua chave.")
            ->success()
            ->getDatabaseMessage();
    }
}
