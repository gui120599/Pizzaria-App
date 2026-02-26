<?php

namespace App\Filament\Resources\Prestadors\Pages;

use App\Filament\Resources\Prestadors\PrestadorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePrestadors extends ManageRecords
{
    protected static string $resource = PrestadorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
