<?php

namespace App\Filament\Resources\Adiantamentos\Pages;

use App\Filament\Resources\Adiantamentos\AdiantamentoResource;
use App\Filament\Resources\Adiantamentos\Support\RegistrarAdiantamentoAction;
use Filament\Resources\Pages\ListRecords;

class ListAdiantamentos extends ListRecords
{
    protected static string $resource = AdiantamentoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RegistrarAdiantamentoAction::make(),
        ];
    }
}
