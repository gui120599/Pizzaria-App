<?php

namespace App\Filament\Resources\Maquininhas\Pages;

use App\Filament\Resources\Maquininhas\MaquininhaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaquininhas extends ListRecords
{
    protected static string $resource = MaquininhaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
