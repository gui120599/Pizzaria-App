<?php

namespace App\Filament\Resources\SetoresProducao\Pages;

use App\Filament\Resources\SetoresProducao\SetorProducaoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSetoresProducao extends ListRecords
{
    protected static string $resource = SetorProducaoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
