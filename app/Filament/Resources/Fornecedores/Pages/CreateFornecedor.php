<?php

namespace App\Filament\Resources\Fornecedores\Pages;

use App\Enums\PrestadorCategoriaEnum;
use App\Filament\Resources\Fornecedores\FornecedorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFornecedor extends CreateRecord
{
    protected static string $resource = FornecedorResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Garante que o registro nasce como Fornecedor.
        $data['categoria'] = PrestadorCategoriaEnum::FORNECEDOR->value;

        return $data;
    }
}
