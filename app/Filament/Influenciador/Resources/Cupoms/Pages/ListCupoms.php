<?php

namespace App\Filament\Influenciador\Resources\Cupoms\Pages;

use App\Filament\Influenciador\Resources\Cupoms\CupomResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCupoms extends ListRecords
{
    protected static string $resource = CupomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
