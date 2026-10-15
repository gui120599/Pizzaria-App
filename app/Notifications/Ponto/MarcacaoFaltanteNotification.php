<?php

namespace App\Notifications\Ponto;

use App\Models\Colaborador;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/**
 * Aviso de marcação faltante. "entrada": ao gestor, a escala previa entrada
 * e ninguém marcou. "saida": ao colaborador, ficou marcação em aberto depois
 * do fim do turno. `colaborador_id`/`dia`/`motivo` evitam aviso repetido.
 */
class MarcacaoFaltanteNotification extends Notification
{
    public const ENTRADA = 'entrada';

    public const SAIDA = 'saida';

    public function __construct(
        public readonly Colaborador $colaborador,
        public readonly CarbonInterface $dia,
        public readonly string $motivo,
        public readonly CarbonInterface $horarioPrevisto,
    ) {}

    /** @return array<int, string> */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        $mensagem = $this->motivo === self::ENTRADA
            ? FilamentNotification::make()
                ->title('Entrada não marcada')
                ->body("{$this->colaborador->nome} tinha entrada prevista às {$this->horarioPrevisto->format('H:i')} e ainda não marcou o ponto.")
            : FilamentNotification::make()
                ->title('Ponto em aberto')
                ->body("Seu turno terminou às {$this->horarioPrevisto->format('H:i')} e ficou marcação sem saída. Marque a saída ou peça ajuste.");

        return [
            ...$mensagem->icon(Heroicon::OutlinedExclamationTriangle)->iconColor('warning')->getDatabaseMessage(),
            'colaborador_id' => $this->colaborador->id,
            'dia' => $this->dia->toDateString(),
            'motivo' => $this->motivo,
        ];
    }
}
