<?php

namespace App\Notifications\Checklist;

use App\Filament\Resources\ChecklistExecucoes\ChecklistExecucaoResource;
use App\Models\ChecklistExecucaoItem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/** Item crítico respondido como não conforme — enviado na hora da resposta, não na finalização. */
class ChecklistItemCriticoNotification extends Notification
{
    public function __construct(public readonly ChecklistExecucaoItem $item) {}

    /** @return array<int, string> */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        $execucao = $this->item->execucao;
        $quem = $this->item->respondidoPor?->name_first ?? 'Equipe';

        return FilamentNotification::make()
            ->title('Item crítico não conforme')
            ->body("{$execucao->modelo_nome}: {$this->item->titulo} — {$this->item->respostaFormatada()} ({$quem}).")
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->iconColor('danger')
            ->actions([
                Action::make('ver')
                    ->label('Ver checklist')
                    ->url(ChecklistExecucaoResource::getUrl('view', ['record' => $this->item->checklist_execucao_id], panel: 'admin'))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
