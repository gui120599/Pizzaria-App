<?php

namespace App\Filament\Resources\LinhasProducao\Pages;

use App\Filament\Resources\LinhasProducao\LinhaProducaoResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLinhaProducao extends EditRecord
{
    protected static string $resource = LinhaProducaoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
