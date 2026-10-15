<?php

namespace App\Filament\Resources\Colaboradores\Pages;

use App\Filament\Resources\Colaboradores\ColaboradorResource;
use App\Filament\Resources\Colaboradores\Support\DesligarColaboradorAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditColaborador extends EditRecord
{
    protected static string $resource = ColaboradorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DesligarColaboradorAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /** CPF e PIX ficam fora do toArray() do model ($hidden); o form precisa deles. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['cpf'] = $this->record->cpf;
        $data['chave_pix'] = $this->record->chave_pix;

        return $data;
    }
}
