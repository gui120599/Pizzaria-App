<?php

namespace App\Filament\Resources\FornecedorProdutos\Pages;

use App\Filament\Resources\FornecedorProdutos\FornecedorProdutoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFornecedorProdutos extends ManageRecords
{
    protected static string $resource = FornecedorProdutoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Novo vínculo')
                ->mutateDataUsing(fn (array $data): array => FornecedorProdutoResource::confirmadoManualmente($data)),
        ];
    }
}
