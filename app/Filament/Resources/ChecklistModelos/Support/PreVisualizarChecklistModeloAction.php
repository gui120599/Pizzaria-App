<?php

namespace App\Filament\Resources\ChecklistModelos\Support;

use App\Models\ChecklistModelo;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

/** Modal com o checklist como a equipe vai ver, só leitura. */
class PreVisualizarChecklistModeloAction
{
    public static function make(string $name = 'preVisualizar'): Action
    {
        return Action::make($name)
            ->label('Pré-visualizar')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->modalHeading(fn (ChecklistModelo $record): string => $record->nome)
            ->modalDescription(fn (ChecklistModelo $record): string => collect([
                $record->tipo->getLabel(),
                $record->setor->getLabel(),
                $record->diasSemanaDescricao(),
                $record->janelaDescricao(),
            ])->filter()->implode(' · '))
            ->modalContent(fn (ChecklistModelo $record): View => view('filament.checklists.pre-visualizar-modelo', [
                'itensPorSecao' => $record->itensOrdenados()->groupBy(fn ($item): string => $item->secao?->titulo ?? 'Sem seção'),
            ]))
            ->slideOver()
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar');
    }
}
