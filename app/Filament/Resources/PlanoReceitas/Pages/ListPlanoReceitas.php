<?php

namespace App\Filament\Resources\PlanoReceitas\Pages;

use App\Filament\Resources\PlanoReceitas\PlanoReceitaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlanoReceitas extends ListRecords
{
    protected static string $resource = PlanoReceitaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
