<?php

namespace App\Filament\Resources\Escalas\Pages;

use App\Filament\Resources\Escalas\EscalaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEscalas extends ListRecords
{
    protected static string $resource = EscalaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
