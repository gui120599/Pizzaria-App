<?php

namespace App\Filament\Resources\FichasTecnicas\Pages;

use App\Filament\Resources\FichasTecnicas\FichaTecnicaResource;
use App\Filament\Resources\FichasTecnicas\Support\DuplicarFichaTecnicaAction;
use App\Filament\Resources\FichasTecnicas\Support\NovaVersaoFichaTecnicaAction;
use App\Filament\Resources\FichasTecnicas\Support\PreVisualizarFichaTecnicaAction;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditFichaTecnica extends EditRecord
{
    protected static string $resource = FichaTecnicaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PreVisualizarFichaTecnicaAction::make(),
            ActionGroup::make([
                DuplicarFichaTecnicaAction::make(),
                NovaVersaoFichaTecnicaAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ]),
        ];
    }
}
