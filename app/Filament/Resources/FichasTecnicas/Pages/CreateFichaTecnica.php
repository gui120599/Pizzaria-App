<?php

namespace App\Filament\Resources\FichasTecnicas\Pages;

use App\Filament\Resources\FichasTecnicas\FichaTecnicaResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Cria só o cabeçalho e os passos. Os ingredientes ficam na edição: o
 * produto pode já ter ficha de custo, e um Repeater vazio na criação apagaria
 * essas linhas ao salvar a relação.
 */
class CreateFichaTecnica extends CreateRecord
{
    protected static string $resource = FichaTecnicaResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['criado_por'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return FichaTecnicaResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
