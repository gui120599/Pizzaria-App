<?php

namespace App\Filament\Resources\Colaboradores\Pages;

use App\Filament\Resources\Colaboradores\ColaboradorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListColaboradores extends ListRecords
{
    protected static string $resource = ColaboradorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
