<?php

namespace App\Filament\Resources\ChecklistNaoConformidades\Pages;

use App\Filament\Resources\ChecklistNaoConformidades\ChecklistNaoConformidadeResource;
use App\Services\Checklist\ChecklistNaoConformidadeService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditChecklistNaoConformidade extends EditRecord
{
    protected static string $resource = ChecklistNaoConformidadeResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(ChecklistNaoConformidadeService::class)->atualizar($record, $data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
