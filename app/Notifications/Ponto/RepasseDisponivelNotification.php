<?php

namespace App\Notifications\Ponto;

use App\Filament\Garcom\Pages\MeusRepasses;
use App\Models\Repasse;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/** Ao colaborador: o repasse do período foi aprovado e está disponível para ciência. */
class RepasseDisponivelNotification extends Notification
{
    public function __construct(public readonly Repasse $repasse) {}

    /** @return array<int, string> */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        $liquido = number_format((float) $this->repasse->valor_liquido, 2, ',', '.');

        return [
            ...FilamentNotification::make()
                ->title('Repasse disponível')
                ->body("Seu repasse de {$this->repasse->periodo->rotulo()} foi aprovado: R$ {$liquido}. Confira os itens e dê ciência.")
                ->icon(Heroicon::OutlinedBanknotes)
                ->iconColor('success')
                ->actions([
                    Action::make('abrir')->label('Ver repasse')->url(MeusRepasses::getUrl(panel: 'garcom'))->markAsRead(),
                ])
                ->getDatabaseMessage(),
            'repasse_id' => $this->repasse->id,
        ];
    }
}
