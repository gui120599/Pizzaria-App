<?php

namespace App\Filament\Resources\ChecklistExecucoes\Pages;

use App\Filament\Resources\ChecklistExecucoes\ChecklistExecucaoResource;
use App\Filament\Support\Cozinha\LiberarProducaoSemAberturaAction;
use Filament\Resources\Pages\ListRecords;

class ListChecklistExecucoes extends ListRecords
{
    protected static string $resource = ChecklistExecucaoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LiberarProducaoSemAberturaAction::make(),
        ];
    }
}
