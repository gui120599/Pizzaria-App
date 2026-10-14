<?php

namespace App\Filament\Resources\SetoresProducao\Pages;

use App\Filament\Resources\SetoresProducao\SetorProducaoResource;
use App\Filament\Resources\SetoresProducao\Tables\SetoresProducaoTable;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditSetorProducao extends EditRecord
{
    protected static string $resource = SetorProducaoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SetoresProducaoTable::excluirAction(),
            RestoreAction::make(),
        ];
    }
}
