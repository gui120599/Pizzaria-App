<?php

namespace App\Filament\Resources\ChecklistModelos\Pages;

use App\Filament\Resources\ChecklistModelos\ChecklistModeloResource;
use Filament\Resources\Pages\CreateRecord;

class CreateChecklistModelo extends CreateRecord
{
    protected static string $resource = ChecklistModeloResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['criado_por'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
