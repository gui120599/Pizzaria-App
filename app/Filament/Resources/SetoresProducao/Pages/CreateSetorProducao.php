<?php

namespace App\Filament\Resources\SetoresProducao\Pages;

use App\Filament\Resources\SetoresProducao\SetorProducaoResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSetorProducao extends CreateRecord
{
    protected static string $resource = SetorProducaoResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['ordem'] = (int) static::getModel()::withTrashed()->max('ordem') + 1;

        return $data;
    }
}
