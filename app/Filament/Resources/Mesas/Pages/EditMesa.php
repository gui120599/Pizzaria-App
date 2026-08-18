<?php

namespace App\Filament\Resources\Mesas\Pages;

use App\Filament\Resources\Mesas\MesaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMesa extends EditRecord
{
    protected static string $resource = MesaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
