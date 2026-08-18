<?php

namespace App\Filament\Resources\SessoesCaixa\Pages;

use App\Filament\Resources\SessoesCaixa\SessaoCaixaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSessaoCaixa extends EditRecord
{
    protected static string $resource = SessaoCaixaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
