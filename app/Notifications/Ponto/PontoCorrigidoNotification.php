<?php

namespace App\Notifications\Ponto;

use App\Enums\Ponto\MotivoAjuste;
use App\Filament\Garcom\Pages\MeuEspelho;
use App\Models\Colaborador;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/** Ao colaborador: o gestor corrigiu o ponto dele (antes/depois, quem e por quê). */
class PontoCorrigidoNotification extends Notification
{
    /**
     * @param  array<int, string>  $antes  horários efetivos "H:i" antes da correção
     * @param  array<int, string>  $depois  horários efetivos "H:i" depois da correção
     */
    public function __construct(
        public readonly Colaborador $colaborador,
        public readonly CarbonInterface $dia,
        public readonly User $gestor,
        public readonly string $acao,
        public readonly MotivoAjuste $motivo,
        public readonly array $antes,
        public readonly array $depois,
    ) {}

    /** @return array<int, string> */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        $antes = $this->antes === [] ? 'sem marcações' : implode(', ', $this->antes);
        $depois = $this->depois === [] ? 'sem marcações' : implode(', ', $this->depois);

        return [
            ...FilamentNotification::make()
                ->title("Seu ponto de {$this->dia->format('d/m')} foi corrigido")
                ->body("{$this->gestor->name_first} {$this->acao} ({$this->motivo->getLabel()}). Antes: {$antes}. Depois: {$depois}.")
                ->icon(Heroicon::OutlinedPencilSquare)
                ->iconColor('warning')
                ->actions([
                    Action::make('abrir')->label('Ver e dar ciência')->url(MeuEspelho::getUrl(panel: 'garcom'))->markAsRead(),
                ])
                ->getDatabaseMessage(),
            'colaborador_id' => $this->colaborador->id,
            'dia' => $this->dia->toDateString(),
        ];
    }
}
