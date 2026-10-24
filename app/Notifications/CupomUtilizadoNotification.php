<?php

namespace App\Notifications;

use App\Models\Influenciador;
use App\Models\Pedido;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class CupomUtilizadoNotification extends Notification
{
    public function __construct(private Pedido $pedido) {}

    /** @return array<int, string> */
    public function via(Influenciador $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(Influenciador $notifiable): array
    {
        $codigo = $this->pedido->cupom?->cupom_codigo ?? '—';
        $valor = number_format((float) $this->pedido->pedido_valor_total, 2, ',', '.');

        return FilamentNotification::make()
            ->title('Cupom utilizado')
            ->body("Seu cupom {$codigo} foi usado em um pedido de R$ {$valor}.")
            ->success()
            ->getDatabaseMessage();
    }
}
