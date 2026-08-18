<?php

namespace App\Filament\Resources\Mesas\Pages;

use App\Filament\Resources\Mesas\MesaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMesas extends ListRecords
{
    protected static string $resource = MesaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
