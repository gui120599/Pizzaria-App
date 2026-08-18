<?php

namespace App\Filament\Resources\SessoesCaixa\Pages;

use App\Filament\Resources\SessoesCaixa\SessaoCaixaResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSessaoCaixa extends CreateRecord
{
    protected static string $resource = SessaoCaixaResource::class;

    /** Espelha SessaoCaixaController::store — abertura sempre gera status ABERTA com saldo final = saldo inicial. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['sessaocaixa_status'] = 'ABERTA';
        $data['sessaocaixa_data_hora_abertura'] = now();
        $data['sessaocaixa_saldo_final'] = $data['sessaocaixa_saldo_inicial'];

        return $data;
    }
}
