<?php

namespace App\Filament\Influenciador\Resources\Cupoms\Pages;

use App\Filament\Influenciador\Resources\Cupoms\CupomResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCupom extends EditRecord
{
    protected static string $resource = CupomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
