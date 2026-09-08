<?php

namespace App\Filament\Resources\Caixas\Pages;

use App\Filament\Resources\Caixas\CaixaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCaixas extends ListRecords
{
    protected static string $resource = CaixaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
