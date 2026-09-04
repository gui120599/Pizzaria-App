<?php

namespace App\Filament\Resources\SessoesCaixa\Pages;

use App\Filament\Resources\SessoesCaixa\SessaoCaixaResource;
use App\Models\SessaoCaixa;
use App\Services\SessaoCaixaService;
use Filament\Resources\Pages\CreateRecord;

class CreateSessaoCaixa extends CreateRecord
{
    protected static string $resource = SessaoCaixaResource::class;

    /** Espelha SessaoCaixaController::store — abertura sempre gera status ABERTA; saldo em dinheiro sai da contagem de notas. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['sessaocaixa_status'] = 'ABERTA';
        $data['sessaocaixa_data_hora_abertura'] = now();

        // Placeholder — o valor real (soma das notas persistidas) é calculado em afterCreate,
        // depois que o Repeater 'notas' grava as linhas relacionadas.
        $data['sessaocaixa_saldo_inicial'] = 0;
        $data['sessaocaixa_saldo_final'] = 0;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var SessaoCaixa $record */
        $record = $this->getRecord();

        app(SessaoCaixaService::class)->finalizarAbertura($record);
    }
}
