<?php

namespace App\Filament\Resources\PromocoesRelampago\Pages;

use App\Filament\Resources\PromocoesRelampago\PromocaoRelampagoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromocoesRelampago extends ListRecords
{
    protected static string $resource = PromocaoRelampagoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
