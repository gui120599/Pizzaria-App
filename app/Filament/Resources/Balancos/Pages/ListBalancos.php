<?php

namespace App\Filament\Resources\Balancos\Pages;

use App\Filament\Resources\Balancos\BalancoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBalancos extends ListRecords
{
    protected static string $resource = BalancoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Novo Balanço'),
        ];
    }
}
