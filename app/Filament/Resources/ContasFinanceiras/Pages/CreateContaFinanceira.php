<?php

namespace App\Filament\Resources\ContasFinanceiras\Pages;

use App\Filament\Resources\ContasFinanceiras\ContaFinanceiraResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContaFinanceira extends CreateRecord
{
    protected static string $resource = ContaFinanceiraResource::class;

    /** O Caixa PDV não tem saldo inicial (o dinheiro dele é o das sessões). */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['saldo_inicial'] ??= 0;
        $data['data_saldo_inicial'] ??= today()->toDateString();
        $data['saldo_atual'] = $data['saldo_inicial'];

        return $data;
    }
}
