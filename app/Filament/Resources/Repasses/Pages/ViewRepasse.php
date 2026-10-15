<?php

namespace App\Filament\Resources\Repasses\Pages;

use App\Filament\Resources\Repasses\RepasseResource;
use App\Filament\Resources\Repasses\Support\RepasseActions;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRepasse extends ViewRecord
{
    protected static string $resource = RepasseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RepasseActions::aprovar(),
            EditAction::make()->label('Itens manuais'),
            RepasseActions::recibo(),
            ActionGroup::make([
                RepasseActions::recalcular(),
                RepasseActions::transferirSaldoDevedor(),
                RepasseActions::cancelarAprovacao(),
                RepasseActions::cancelar(),
            ]),
        ];
    }
}
