<?php

namespace App\Filament\Resources\MovimentacaoProdutos\Pages;

use App\Filament\Resources\MovimentacaoProdutos\MovimentacaoProdutoResource;
use Filament\Resources\Pages\ManageRecords;

class ManageMovimentacaoProdutos extends ManageRecords
{
    protected static string $resource = MovimentacaoProdutoResource::class;

    protected function getHeaderActions(): array
    {
        // Livro-razão imutável — sem criação manual (usa o EstoqueService).
        return [];
    }
}
