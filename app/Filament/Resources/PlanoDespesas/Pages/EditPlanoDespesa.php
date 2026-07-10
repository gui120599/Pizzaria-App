<?php

namespace App\Filament\Resources\PlanoDespesas\Pages;

use App\Filament\Resources\PlanoDespesas\PlanoDespesaResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPlanoDespesa extends EditRecord
{
    protected static string $resource = PlanoDespesaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}
