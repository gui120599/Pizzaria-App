<?php

namespace App\Filament\Resources\OpcoesEntregas\Pages;

use App\Filament\Resources\OpcoesEntregas\OpcoesEntregasResource;
use Filament\Resources\Pages\ManageRecords;

class ManageOpcoesEntregas extends ManageRecords
{
    protected static string $resource = OpcoesEntregasResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
