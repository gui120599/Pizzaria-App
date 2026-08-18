<?php

namespace App\Filament\Resources\SessoesCaixa\Pages;

use App\Filament\Resources\SessoesCaixa\SessaoCaixaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSessoesCaixa extends ListRecords
{
    protected static string $resource = SessaoCaixaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
