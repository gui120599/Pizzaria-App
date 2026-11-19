<?php

namespace App\Filament\Resources\PromocoesCombo\Pages;

use App\Filament\Resources\PromocoesCombo\PromocaoComboResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPromocaoCombo extends EditRecord
{
    protected static string $resource = PromocaoComboResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    /** Itens por combo = soma dos grupos (os grupos são gravados depois do registro). */
    protected function afterSave(): void
    {
        $this->record->sincronizarQuantidade();
    }
}
