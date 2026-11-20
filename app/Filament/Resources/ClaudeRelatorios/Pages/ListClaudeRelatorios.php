<?php

namespace App\Filament\Resources\ClaudeRelatorios\Pages;

use App\Filament\Resources\ClaudeRelatorios\ClaudeRelatorioResource;
use Filament\Resources\Pages\ListRecords;

class ListClaudeRelatorios extends ListRecords
{
    protected static string $resource = ClaudeRelatorioResource::class;

    public function getSubheading(): ?string
    {
        return 'Gravados pelas rotinas agendadas do Claude no conector "PizzariaAPP Gestão".';
    }
}
