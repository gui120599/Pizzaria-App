<?php

namespace App\Filament\Resources\OpcoesPagamento\Pages;

use App\Filament\Resources\OpcoesPagamento\OpcoesPagamentoResource;
use Filament\Resources\Pages\ManageRecords;

class ManageOpcoesPagamento extends ManageRecords
{
    protected static string $resource = OpcoesPagamentoResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
