<?php

namespace App\Filament\Resources\Caixas\Pages;

use App\Filament\Resources\Caixas\CaixaResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCaixa extends EditRecord
{
    protected static string $resource = CaixaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
