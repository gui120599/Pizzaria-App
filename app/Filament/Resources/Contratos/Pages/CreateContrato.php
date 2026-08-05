<?php

namespace App\Filament\Resources\Contratos\Pages;

use App\Filament\Resources\Contratos\ContratoResource;
use App\Filament\Resources\Contratos\Support\PreparaContrato;
use Filament\Resources\Pages\CreateRecord;

class CreateContrato extends CreateRecord
{
    use PreparaContrato;

    protected static string $resource = ContratoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->validarContrato($data);
    }
}
