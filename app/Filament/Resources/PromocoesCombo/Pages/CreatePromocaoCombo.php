<?php

namespace App\Filament\Resources\PromocoesCombo\Pages;

use App\Filament\Resources\PromocoesCombo\PromocaoComboResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePromocaoCombo extends CreateRecord
{
    protected static string $resource = PromocaoComboResource::class;

    /** Itens por combo = soma dos grupos (os grupos são gravados depois do registro). */
    protected function afterCreate(): void
    {
        $this->record->sincronizarQuantidade();
    }
}
