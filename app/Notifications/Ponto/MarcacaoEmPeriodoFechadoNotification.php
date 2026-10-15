<?php

namespace App\Notifications\Ponto;

use App\Models\PontoMarcacao;
use App\Models\PontoPeriodo;
use App\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/** Aos gestores: chegou marcação num dia cujo período já está travado (gravada, mas não apurada). */
class MarcacaoEmPeriodoFechadoNotification extends Notification
{
    public function __construct(
        public readonly PontoMarcacao $marcacao,
        public readonly PontoPeriodo $periodo,
    ) {}

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
                ->title('Marcação em período travado')
                ->body("{$this->marcacao->colaborador->nome} marcou às {$this->marcacao->registrada_em->format('d/m H:i')}, dentro do período {$this->periodo->rotulo()} ({$this->periodo->status->getLabel()}). A marcação foi gravada, mas não entra na apuração até o período ser reaberto.")
                ->icon(Heroicon::OutlinedLockClosed)
                ->iconColor('danger')
                ->getDatabaseMessage(),
            'ponto_marcacao_id' => $this->marcacao->id,
        ];
    }
}
