<?php

namespace App\Filament\Resources\Colaboradores\Pages;

use App\Filament\Resources\Colaboradores\ColaboradorResource;
use Filament\Resources\Pages\CreateRecord;

/** Remuneração e escala entram depois, nas abas do cadastro (vigências). */
class CreateColaborador extends CreateRecord
{
    protected static string $resource = ColaboradorResource::class;

    protected function getRedirectUrl(): string
    {
        return ColaboradorResource::getUrl('edit', ['record' => $this->record]);
    }
}
