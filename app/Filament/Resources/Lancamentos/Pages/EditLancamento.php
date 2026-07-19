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
}
