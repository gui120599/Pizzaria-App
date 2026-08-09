<?php

namespace App\Filament\Resources\PrazosPagamento\Pages;

use App\Filament\Resources\PrazosPagamento\PrazoPagamentoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPrazosPagamento extends ListRecords
{
    protected static string $resource = PrazoPagamentoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
