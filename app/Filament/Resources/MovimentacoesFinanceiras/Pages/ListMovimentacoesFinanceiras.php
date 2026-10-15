<?php

namespace App\Filament\Resources\MovimentacoesFinanceiras\Pages;

use App\Filament\Resources\ContasFinanceiras\Support\TransferirEntreContasAction;
use App\Filament\Resources\MovimentacoesFinanceiras\MovimentacaoFinanceiraResource;
use Filament\Resources\Pages\ListRecords;

class ListMovimentacoesFinanceiras extends ListRecords
{
    protected static string $resource = MovimentacaoFinanceiraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TransferirEntreContasAction::make(),
        ];
    }
}
