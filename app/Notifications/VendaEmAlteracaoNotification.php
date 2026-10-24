<?php

namespace App\Notifications;

use App\Filament\Pages\OperarVenda;
use App\Models\User;
use App\Models\VendaAlteracao;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/** Gerente: venda reaberta para alteração passou do prazo sem ser finalizada (vendas:alertar-alteracoes-abertas). */
class VendaEmAlteracaoNotification extends Notification
{
    public function __construct(public readonly VendaAlteracao $alteracao) {}

    /** @return array<int, string> */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        $horas = max(1, (int) $this->alteracao->created_at->diffInHours(now()));

        return FilamentNotification::make()
            ->title("Venda #{$this->alteracao->venda_id} em alteração há {$horas} h")
            ->body('Reaberta por '.($this->alteracao->user?->name ?? 'usuário removido').": {$this->alteracao->motivo}. O caixa da sessão está sem a entrada desta venda até ela ser finalizada ou descartada.")
            ->icon(Heroicon::OutlinedPencilSquare)
            ->iconColor('warning')
            ->actions([
                Action::make('abrir')
                    ->label('Abrir a venda')
                    ->url(OperarVenda::getUrl(['venda' => $this->alteracao->venda_id])),
            ])
            ->getDatabaseMessage();
    }
}
