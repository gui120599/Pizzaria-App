<?php

namespace App\Filament\Garcom\Resources\ChecklistExecucoes\Pages;

use App\Filament\Garcom\Resources\ChecklistExecucoes\ChecklistExecucaoResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewChecklistExecucao extends ViewRecord
{
    protected static string $resource = ChecklistExecucaoResource::class;

    public function getTitle(): string|Htmlable
    {
        return "{$this->getRecord()->modelo_nome} — {$this->getRecord()->data_referencia->format('d/m/Y')}";
    }
}
