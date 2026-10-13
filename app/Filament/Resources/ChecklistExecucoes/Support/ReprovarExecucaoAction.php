<?php

namespace App\Filament\Resources\ChecklistExecucoes\Support;

use App\Exceptions\ChecklistException;
use App\Models\ChecklistExecucao;
use App\Services\Checklist\ChecklistExecucaoService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** Devolve para a equipe, que reabre a execução no /garcom para corrigir. */
class ReprovarExecucaoAction
{
    public static function make(string $name = 'reprovar'): Action
    {
        return Action::make($name)
            ->label('Reprovar')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('aprovar')
            ->visible(fn (ChecklistExecucao $record): bool => $record->status->aguardaAprovacao())
            ->modalHeading('Reprovar checklist')
            ->modalDescription('A equipe vê o motivo e reabre o checklist para corrigir.')
            ->modalSubmitActionLabel('Reprovar')
            ->schema([
                Textarea::make('motivo')
                    ->label('Motivo')
                    ->required()
                    ->maxLength(2000)
                    ->rows(3),
            ])
            ->action(function (ChecklistExecucao $record, array $data, ChecklistExecucaoService $service): void {
                try {
                    $service->reprovar($record, auth()->user(), $data['motivo']);
                } catch (ChecklistException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Checklist reprovado')->body('A equipe foi devolvida para correção.')->warning()->send();
            });
    }
}
