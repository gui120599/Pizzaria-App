<?php

namespace App\Filament\Resources\ChecklistExecucoes\Pages;

use App\Filament\Resources\ChecklistExecucoes\ChecklistExecucaoResource;
use App\Filament\Resources\ChecklistExecucoes\Support\AprovarExecucaoAction;
use App\Filament\Resources\ChecklistExecucoes\Support\ReprovarExecucaoAction;
use Filament\Resources\Pages\ViewRecord;

class ViewChecklistExecucao extends ViewRecord
{
    protected static string $resource = ChecklistExecucaoResource::class;

    public function getTitle(): string
    {
        return "{$this->getRecord()->modelo_nome} — {$this->getRecord()->data_referencia->format('d/m/Y')}";
    }

    protected function getHeaderActions(): array
    {
        return [
            AprovarExecucaoAction::make(),
            ReprovarExecucaoAction::make(),
        ];
    }
}
