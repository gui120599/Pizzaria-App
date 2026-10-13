<?php

namespace App\Notifications\Checklist;

use App\Filament\Resources\ChecklistExecucoes\ChecklistExecucaoResource;
use App\Models\ChecklistExecucao;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

class ChecklistAguardandoAprovacaoNotification extends Notification
{
    public function __construct(public readonly ChecklistExecucao $execucao) {}

    /** @return array<int, string> */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        $conformidade = $this->execucao->percentual_conformidade === null
            ? ''
            : ' — '.number_format((float) $this->execucao->percentual_conformidade, 0, ',', '.').'% de conformidade';

        return FilamentNotification::make()
            ->title('Checklist aguardando aprovação')
            ->body("{$this->execucao->modelo_nome} de {$this->execucao->data_referencia->format('d/m/Y')}{$conformidade}.")
            ->icon(Heroicon::OutlinedCheckBadge)
            ->iconColor('warning')
            ->actions([
                Action::make('ver')
                    ->label('Ver checklist')
                    ->url(ChecklistExecucaoResource::getUrl('view', ['record' => $this->execucao->id], panel: 'admin'))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
