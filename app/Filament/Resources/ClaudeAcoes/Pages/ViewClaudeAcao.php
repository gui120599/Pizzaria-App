<?php

namespace App\Filament\Resources\ClaudeAcoes\Pages;

use App\Filament\Resources\ClaudeAcoes\ClaudeAcaoResource;
use Filament\Resources\Pages\ViewRecord;

class ViewClaudeAcao extends ViewRecord
{
    protected static string $resource = ClaudeAcaoResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->tipo->getLabel().' #'.$this->getRecord()->id;
    }
}
