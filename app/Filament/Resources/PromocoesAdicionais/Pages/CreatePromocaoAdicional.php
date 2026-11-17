<?php

namespace App\Filament\Resources\PromocoesAdicionais\Pages;

use App\Filament\Resources\PromocoesAdicionais\PromocaoAdicionalResource;
use App\Filament\Resources\PromocoesAdicionais\Schemas\PromocaoAdicionalForm;
use Filament\Resources\Pages\CreateRecord;

class CreatePromocaoAdicional extends CreateRecord
{
    protected static string $resource = PromocaoAdicionalResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return PromocaoAdicionalForm::normalizarQuantidadeLimitada($data);
    }
}
