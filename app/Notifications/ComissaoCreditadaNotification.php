<?php

namespace App\Notifications;

use App\Models\Comissao;
use App\Models\Influenciador;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class ComissaoCreditadaNotification extends Notification
{
    public function __construct(private Comissao $comissao) {}

    /** @return array<int, string> */
    public function via(Influenciador $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(Influenciador $notifiable): array
    {
        $valor = number_format((float) $this->comissao->comissao_valor, 2, ',', '.');

        return FilamentNotification::make()
            ->title('Comissão creditada')
            ->body("R$ {$valor} entraram na sua carteira (pedido #{$this->comissao->comissao_pedido_id}).")
            ->success()
            ->getDatabaseMessage();
    }
}
