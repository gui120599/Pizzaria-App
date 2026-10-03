<?php

namespace App\Filament\Resources\Adicionais\Pages;

use App\Filament\Resources\Adicionais\AdicionalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAdicionais extends ManageRecords
{
    protected static string $resource = AdicionalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
