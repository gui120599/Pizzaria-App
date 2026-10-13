<?php

namespace App\Filament\Resources\ChecklistModelos\Pages;

use App\Filament\Resources\ChecklistModelos\ChecklistModeloResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChecklistModelos extends ListRecords
{
    protected static string $resource = ChecklistModeloResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
