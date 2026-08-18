<?php

namespace App\Filament\Resources\SessoesMesa\Pages;

use App\Filament\Resources\SessoesMesa\SessaoMesaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSessoesMesa extends ListRecords
{
    protected static string $resource = SessaoMesaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
