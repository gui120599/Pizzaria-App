<?php

namespace App\Filament\Resources\PromocoesAdicionais\Pages;

use App\Filament\Resources\PromocoesAdicionais\PromocaoAdicionalResource;
use App\Filament\Resources\PromocoesAdicionais\Schemas\PromocaoAdicionalForm;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPromocaoAdicional extends EditRecord
{
    protected static string $resource = PromocaoAdicionalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PromocaoAdicionalForm::normalizarQuantidadeLimitada($data);
    }
}
