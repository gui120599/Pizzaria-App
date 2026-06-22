<?php

namespace App\Filament\Resources\Fornecedores\Pages;

use App\Filament\Resources\Fornecedores\FornecedorResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFornecedor extends EditRecord
{
    protected static string $resource = FornecedorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
