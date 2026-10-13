<?php

namespace App\Filament\Resources\ChecklistExecucoes\Support;

use App\Exceptions\ChecklistException;
use App\Models\ChecklistExecucao;
use App\Services\Checklist\ChecklistExecucaoService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class AprovarExecucaoAction
{
    public static function make(string $name = 'aprovar'): Action
    {
        return Action::make($name)
            ->label('Aprovar')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->authorize('aprovar')
            ->visible(fn (ChecklistExecucao $record): bool => $record->status->aguardaAprovacao())
            ->requiresConfirmation()
            ->modalHeading('Aprovar checklist')
            ->modalDescription(fn (ChecklistExecucao $record): string => $record->itens_nao_conformes > 0
                ? "Há {$record->itens_nao_conformes} item(ns) não conforme(s); as não conformidades continuam abertas para tratamento."
                : 'Todos os itens avaliados estão conformes.')
            ->modalSubmitActionLabel('Aprovar')
            ->action(function (ChecklistExecucao $record, ChecklistExecucaoService $service): void {
                try {
                    $service->aprovar($record, auth()->user());
                } catch (ChecklistException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Checklist aprovado')->success()->send();
            });
    }
}
