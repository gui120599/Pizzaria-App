<?php

namespace App\Filament\Resources\SessoesMesa\Pages;

use App\Filament\Resources\SessoesMesa\SessaoMesaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSessaoMesa extends EditRecord
{
    protected static string $resource = SessaoMesaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
