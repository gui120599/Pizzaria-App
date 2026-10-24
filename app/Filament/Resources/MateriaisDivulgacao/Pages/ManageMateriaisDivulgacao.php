<?php

namespace App\Filament\Resources\MateriaisDivulgacao\Pages;

use App\Filament\Resources\MateriaisDivulgacao\MaterialDivulgacaoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMateriaisDivulgacao extends ManageRecords
{
    protected static string $resource = MaterialDivulgacaoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
