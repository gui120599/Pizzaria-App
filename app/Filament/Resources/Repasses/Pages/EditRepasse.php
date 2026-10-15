<?php

namespace App\Filament\Resources\Repasses\Pages;

use App\Filament\Resources\Repasses\RepasseResource;
use Filament\Resources\Pages\EditRecord;

/** Itens manuais (INSS/IRRF do holerite, bônus…) e conferência do holerite; só no rascunho (RepassePolicy::update). */
class EditRepasse extends EditRecord
{
    protected static string $resource = RepasseResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function afterSave(): void
    {
        $this->record->recalcularTotais();
    }

    protected function getRedirectUrl(): string
    {
        return RepasseResource::getUrl('view', ['record' => $this->record]);
    }
}
