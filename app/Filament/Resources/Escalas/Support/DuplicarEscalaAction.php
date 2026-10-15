<?php

namespace App\Filament\Resources\Escalas\Support;

use App\Filament\Resources\Escalas\EscalaResource;
use App\Models\Escala;
use App\Models\EscalaDia;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/** Copia a escala com os dias, para criar uma variação sem redigitar. */
class DuplicarEscalaAction
{
    public static function make(string $name = 'duplicar'): Action
    {
        return Action::make($name)
            ->label('Duplicar')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->authorize('create')
            ->requiresConfirmation()
            ->modalHeading('Duplicar escala')
            ->action(function (Escala $record, Action $action): void {
                $copia = DB::transaction(function () use ($record): Escala {
                    $copia = $record->replicate();
                    $copia->nome = str($record->nome.' (cópia)')->limit(80, '')->toString();
                    $copia->save();

                    $record->dias->each(fn (EscalaDia $dia) => $copia->dias()->create($dia->only(['dia_semana', 'folga', 'entrada', 'saida', 'intervalo_minutos'])));

                    return $copia;
                });

                Notification::make()->title('Escala duplicada')->success()->send();
                $action->redirect(EscalaResource::getUrl('edit', ['record' => $copia]));
            });
    }
}
