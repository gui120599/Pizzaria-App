<?php

namespace App\Filament\Resources\SessoesCaixa\Pages;

use App\Enums\FormaPagamento;
use App\Filament\Resources\SessoesCaixa\SessaoCaixaResource;
use App\Models\SessaoCaixa;
use App\Services\SessaoCaixaService;
use Filament\Resources\Pages\CreateRecord;

class CreateSessaoCaixa extends CreateRecord
{
    protected static string $resource = SessaoCaixaResource::class;

    /** Saldo adicional (débito/crédito/pix) digitado no form — não são colunas de SessaoCaixa, viram movimento. */
    private array $saldoAdicionalAbertura = [];

    /** Espelha SessaoCaixaController::store — abertura sempre gera status ABERTA; saldo em dinheiro sai da contagem de notas. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['sessaocaixa_status'] = 'ABERTA';
        $data['sessaocaixa_data_hora_abertura'] = now();

        $this->saldoAdicionalAbertura = [
            FormaPagamento::CartaoDebito->value => (float) ($data['abertura_valor_debito'] ?? 0),
            FormaPagamento::CartaoCredito->value => (float) ($data['abertura_valor_credito'] ?? 0),
            FormaPagamento::Pix->value => (float) ($data['abertura_valor_pix'] ?? 0),
        ];

        unset($data['abertura_valor_debito'], $data['abertura_valor_credito'], $data['abertura_valor_pix']);

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

        $totalDinheiro = round((float) $record->notas()->sum('valor_total'), 2);

        $record->update([
            'sessaocaixa_saldo_inicial' => $totalDinheiro,
            'sessaocaixa_saldo_final' => $totalDinheiro,
        ]);

        app(SessaoCaixaService::class)->registrarMovimentoAbertura(
            $record,
            [FormaPagamento::Dinheiro->value => $totalDinheiro, ...$this->saldoAdicionalAbertura],
        );
    }
}
