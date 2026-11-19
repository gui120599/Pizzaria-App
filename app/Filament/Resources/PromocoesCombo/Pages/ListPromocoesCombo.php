<?php

namespace App\Filament\Resources\PromocoesCombo\Pages;

use App\Filament\Resources\PromocoesCombo\PromocaoComboResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromocoesCombo extends ListRecords
{
    protected static string $resource = PromocaoComboResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
