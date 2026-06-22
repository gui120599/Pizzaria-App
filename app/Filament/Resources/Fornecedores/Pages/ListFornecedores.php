<?php

namespace App\Filament\Resources\Fornecedores\Pages;

use App\Filament\Resources\Fornecedores\FornecedorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFornecedores extends ListRecords
{
    protected static string $resource = FornecedorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
