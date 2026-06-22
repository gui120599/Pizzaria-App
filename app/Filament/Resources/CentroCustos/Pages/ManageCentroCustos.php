<?php

namespace App\Filament\Resources\CentroCustos\Pages;

use App\Filament\Resources\CentroCustos\CentroCustoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCentroCustos extends ManageRecords
{
    protected static string $resource = CentroCustoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
