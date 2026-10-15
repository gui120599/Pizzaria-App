<?php

namespace App\Filament\Resources\ContasFinanceiras\Pages;

use App\Filament\Resources\ContasFinanceiras\ContaFinanceiraResource;
use App\Services\Financeiro\MovimentacaoService;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditContaFinanceira extends EditRecord
{
    protected static string $resource = ContaFinanceiraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Desativar'),
            RestoreAction::make()->label('Reativar'),
        ];
    }

    /** Saldo inicial ou data mudaram: o saldo atual é reconstruído a partir das movimentações. */
    protected function afterSave(): void
    {
        if ($this->record->wasChanged(['saldo_inicial', 'data_saldo_inicial'])) {
            app(MovimentacaoService::class)->recalcularSaldo($this->record);
            $this->record->refresh();
        }
    }
}
