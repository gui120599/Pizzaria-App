<?php

namespace App\Filament\Resources\Lancamentos\Pages;

use App\Filament\Resources\Lancamentos\LancamentoResource;
use App\Filament\Resources\Lancamentos\Support\PreparaLancamento;
use Filament\Resources\Pages\CreateRecord;

class CreateLancamento extends CreateRecord
{
    use PreparaLancamento;

    protected static string $resource = LancamentoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->prepararDados($data);
    }
}
