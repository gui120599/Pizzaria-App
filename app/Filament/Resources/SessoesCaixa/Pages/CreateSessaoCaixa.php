<?php

namespace App\Filament\Resources\SessoesCaixa\Pages;

use App\Exceptions\FinanceiroException;
use App\Filament\Resources\SessoesCaixa\SessaoCaixaResource;
use App\Models\SessaoCaixa;
use App\Services\SessaoCaixaService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateSessaoCaixa extends CreateRecord
{
    protected static string $resource = SessaoCaixaResource::class;

    private ?int $contaTrocoInicialId = null;

    private bool $ajusteImplantacao = false;

    /** Espelha SessaoCaixaController::store — abertura sempre gera status ABERTA; saldo em dinheiro sai da contagem de notas. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Origem do troco inicial (implantação): não é coluna da sessão, vai para o serviço em afterCreate.
        $this->contaTrocoInicialId = filled($data['implantacao_conta_id'] ?? null) ? (int) $data['implantacao_conta_id'] : null;
        $this->ajusteImplantacao = (bool) ($data['implantacao_ajuste'] ?? false);
        unset($data['implantacao_conta_id'], $data['implantacao_ajuste']);

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

        $service = app(SessaoCaixaService::class);
        $service->finalizarAbertura($record);
        $service->conciliarAbertura($record->refresh());

        try {
            $service->lancarTrocoInicial($record, $this->contaTrocoInicialId, $this->ajusteImplantacao);
        } catch (FinanceiroException $e) {
            Notification::make()->title('Troco inicial não lançado no Caixa PDV')->body($e->getMessage())->warning()->persistent()->send();
        }
    }
}
