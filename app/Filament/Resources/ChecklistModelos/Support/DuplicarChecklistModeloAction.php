<?php

namespace App\Filament\Resources\ChecklistModelos\Support;

use App\Filament\Resources\ChecklistModelos\ChecklistModeloResource;
use App\Models\ChecklistModelo;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** Copia modelo + seções + itens + papéis; a cópia nasce inativa (ChecklistModelo::duplicar()). */
class DuplicarChecklistModeloAction
{
    public static function make(string $name = 'duplicar'): Action
    {
        return Action::make($name)
            ->label('Duplicar')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->authorize('replicate')
            ->requiresConfirmation()
            ->modalHeading('Duplicar modelo de checklist')
            ->modalDescription('A cópia leva seções, itens e papéis e nasce inativa, para você ajustar antes de usar.')
            ->modalSubmitActionLabel('Duplicar')
            ->action(function (ChecklistModelo $record, Action $action): void {
                $copia = $record->duplicar(auth()->user());

                Notification::make()
                    ->title('Modelo duplicado')
                    ->body("\"{$copia->nome}\" foi criado inativo.")
                    ->success()
                    ->send();

                $action->redirect(ChecklistModeloResource::getUrl('edit', ['record' => $copia]));
            });
    }
}
