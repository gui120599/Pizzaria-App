<?php

namespace App\Filament\Resources\Escalas\Pages;

use App\Filament\Resources\Escalas\EscalaResource;
use App\Filament\Resources\Escalas\Support\DuplicarEscalaAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditEscala extends EditRecord
{
    protected static string $resource = EscalaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DuplicarEscalaAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
