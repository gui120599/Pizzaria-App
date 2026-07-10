<?php

namespace App\Filament\Resources\PlanoDespesas\Pages;

use App\Filament\Resources\PlanoDespesas\PlanoDespesaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlanoDespesas extends ListRecords
{
    protected static string $resource = PlanoDespesaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
