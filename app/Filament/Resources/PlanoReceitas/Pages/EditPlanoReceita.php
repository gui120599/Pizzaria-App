<?php

namespace App\Filament\Resources\PlanoReceitas\Pages;

use App\Filament\Resources\PlanoReceitas\PlanoReceitaResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPlanoReceita extends EditRecord
{
    protected static string $resource = PlanoReceitaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}
