<?php

namespace App\Filament\Resources\Lancamentos\Pages;

use App\Exceptions\FinanceiroException;
use App\Filament\Resources\Lancamentos\LancamentoResource;
use App\Filament\Resources\Lancamentos\Support\PreparaLancamento;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateLancamento extends CreateRecord
{
    use PreparaLancamento;

    protected static string $resource = LancamentoResource::class;

    /**
     * Em transação: os pagamentos do Repeater movimentam contas financeiras
     * (ver BaixaFinanceira), e uma recusa (saldo, sessão de caixa fechada)
     * desfaz o salvamento inteiro.
     */
    protected ?bool $hasDatabaseTransactions = true;

    public function create(bool $another = false): void
    {
        try {
            parent::create($another);
        } catch (FinanceiroException $e) {
            Notification::make()->title('Lançamento não salvo')->body($e->getMessage())->danger()->send();
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->prepararDados($data);
    }
}
