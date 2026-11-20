<?php

namespace App\Filament\Resources\ClaudeRelatorios\Pages;

use App\Filament\Resources\ClaudeRelatorios\ClaudeRelatorioResource;
use Filament\Resources\Pages\ViewRecord;

class ViewClaudeRelatorio extends ViewRecord
{
    protected static string $resource = ClaudeRelatorioResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->titulo;
    }
}
