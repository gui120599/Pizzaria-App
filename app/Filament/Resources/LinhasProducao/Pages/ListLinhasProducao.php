<?php

namespace App\Filament\Resources\LinhasProducao\Pages;

use App\Filament\Resources\LinhasProducao\LinhaProducaoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLinhasProducao extends ListRecords
{
    protected static string $resource = LinhaProducaoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
