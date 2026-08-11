<?php

namespace App\Filament\Resources\Lancamentos\Pages;

use App\Filament\Resources\Lancamentos\LancamentoResource;
use App\Filament\Resources\Lancamentos\Support\PreparaLancamento;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLancamento extends EditRecord
{
    use PreparaLancamento;

    protected static string $resource = LancamentoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->prepararDados($data, $this->getRecord());
    }

    /**
     * O repeater "pagamentos" (->relationship()) persiste os pagamentos ANTES de
     * handleRecordUpdate(), disparando Lancamento::recalcularStatus() numa instância
     * separada do model (carregada via $pagamento->lancamento). Isso deixa
     * $this->getRecord() com o status desatualizado em memória (ex.: ainda "Pendente"
     * mesmo já tendo virado "Pago" no banco). Sem esse refresh, getRedirectUrl() erra
     * a checagem de authorizeAccess() e decide NÃO redirecionar — a página fica
     * "presa" num título que já não pode mais ser editado, e a próxima interação
     * (hydrate) aborta com 403. Atualizando o record aqui, o redirecionamento para a
     * listagem acontece corretamente assim que o título vira Pago.
     */
    protected function afterSave(): void
    {
        $this->record->refresh();
    }
}
