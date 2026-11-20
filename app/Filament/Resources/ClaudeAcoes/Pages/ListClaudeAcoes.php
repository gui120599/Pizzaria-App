<?php

namespace App\Filament\Resources\ClaudeAcoes\Pages;

use App\Filament\Resources\ClaudeAcoes\ClaudeAcaoResource;
use Filament\Resources\Pages\ListRecords;

class ListClaudeAcoes extends ListRecords
{
    protected static string $resource = ClaudeAcaoResource::class;

    public function getSubheading(): ?string
    {
        return 'Preparadas e confirmadas pelo chat no conector "PizzariaAPP Gestão".';
    }
}
