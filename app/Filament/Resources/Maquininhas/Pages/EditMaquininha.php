<?php

namespace App\Filament\Resources\Maquininhas\Pages;

use App\Filament\Resources\Maquininhas\MaquininhaResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditMaquininha extends EditRecord
{
    protected static string $resource = MaquininhaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
