<?php

namespace App\Filament\Resources\Influenciadores\Pages;

use App\Filament\Resources\Influenciadores\InfluenciadorResource;
use App\Models\Influenciador;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditInfluenciador extends EditRecord
{
    protected static string $resource = InfluenciadorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InfluenciadorResource::redefinirSenhaAction(),
            DeleteAction::make()
                ->before(function (DeleteAction $action, Influenciador $record): void {
                    InfluenciadorResource::barrarExclusaoSeBloqueada($action, $record);
                }),
            RestoreAction::make(),
        ];
    }
}
