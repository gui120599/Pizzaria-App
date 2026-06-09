<?php

namespace App\Filament\Resources\AvaliacaoLinks\Pages;

use App\Filament\Resources\AvaliacaoLinks\AvaliacaoLinksResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAvaliacaoLinks extends ManageRecords
{
    protected static string $resource = AvaliacaoLinksResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
