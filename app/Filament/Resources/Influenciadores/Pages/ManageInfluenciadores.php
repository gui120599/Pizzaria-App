<?php

namespace App\Filament\Resources\Influenciadores\Pages;

use App\Filament\Resources\Influenciadores\InfluenciadorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageInfluenciadores extends ManageRecords
{
    protected static string $resource = InfluenciadorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
