<?php

namespace App\Filament\Resources\FichasTecnicas\Pages;

use App\Filament\Resources\FichasTecnicas\FichaTecnicaResource;
use App\Filament\Resources\FichasTecnicas\Support\ProdutosSemFichaAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFichasTecnicas extends ListRecords
{
    protected static string $resource = FichaTecnicaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ProdutosSemFichaAction::make(),
            CreateAction::make(),
        ];
    }
}
