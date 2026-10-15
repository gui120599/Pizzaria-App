<?php

namespace App\Notifications\Ponto;

use App\Enums\Ponto\StatusAjuste;
use App\Filament\Garcom\Pages\MeuEspelho;
use App\Models\PontoAjuste;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/** Ao colaborador: o ajuste que ele pediu foi aprovado ou rejeitado. */
class AjusteDecididoNotification extends Notification
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
        $aprovado = $this->ajuste->status === StatusAjuste::Aprovado;
        $dia = $this->ajuste->data_referencia->format('d/m/Y');

        return [
            ...FilamentNotification::make()
                ->title($aprovado ? 'Ajuste de ponto aprovado' : 'Ajuste de ponto rejeitado')
                ->body($aprovado
                    ? "Seu pedido de {$this->ajuste->tipo->getLabel()} em {$dia} foi aprovado."
                    : "Seu pedido de {$this->ajuste->tipo->getLabel()} em {$dia} foi rejeitado: {$this->ajuste->motivo_rejeicao}")
                ->icon($aprovado ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedXCircle)
                ->iconColor($aprovado ? 'success' : 'danger')
                ->actions([
                    Action::make('abrir')->label('Ver meu espelho')->url(MeuEspelho::getUrl(panel: 'garcom'))->markAsRead(),
                ])
                ->getDatabaseMessage(),
            'ponto_ajuste_id' => $this->ajuste->id,
        ];
    }
}
