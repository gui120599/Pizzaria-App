<?php

namespace App\Filament\Resources\Compras\Pages;

use App\Filament\Resources\Compras\CompraResource;
use App\Filament\Resources\Compras\Support\BuscarNfePorChaveAction;
use App\Filament\Resources\Compras\Support\ImportarXmlAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompras extends ListRecords
{
    protected static string $resource = CompraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            BuscarNfePorChaveAction::make(),
            ImportarXmlAction::make(),
            CreateAction::make(),
        ];
    }
}
