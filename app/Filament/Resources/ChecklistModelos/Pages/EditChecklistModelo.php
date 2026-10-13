<?php

namespace App\Filament\Resources\ChecklistModelos\Pages;

use App\Filament\Resources\ChecklistModelos\ChecklistModeloResource;
use App\Filament\Resources\ChecklistModelos\Support\DuplicarChecklistModeloAction;
use App\Filament\Resources\ChecklistModelos\Support\PreVisualizarChecklistModeloAction;
use App\Services\Checklist\ChecklistModeloService;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * Versionamento: a assinatura do conteúdo é capturada no beforeSave (antes de
 * o Filament salvar os Repeaters de seções/itens) e comparada no afterSave
 * — ver ChecklistModeloService.
 */
class EditChecklistModelo extends EditRecord
{
    protected static string $resource = ChecklistModeloResource::class;

    protected ?string $assinaturaAntesDeSalvar = null;

    protected function getHeaderActions(): array
    {
        return [
            PreVisualizarChecklistModeloAction::make(),
            DuplicarChecklistModeloAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        $this->assinaturaAntesDeSalvar = app(ChecklistModeloService::class)->assinatura($this->getRecord());
    }

    protected function afterSave(): void
    {
        $modelo = $this->getRecord();

        if (! app(ChecklistModeloService::class)->aplicarNovaVersao($modelo, (string) $this->assinaturaAntesDeSalvar)) {
            return;
        }

        Notification::make()
            ->title("Modelo atualizado para a versão {$modelo->fresh()->versao}")
            ->body('Execuções pendentes sem respostas foram atualizadas. As que já estão em andamento continuam com a versão anterior.')
            ->info()
            ->send();
    }
}
