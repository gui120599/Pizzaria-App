<?php

namespace App\Filament\Resources\PrazosPagamento\Pages;

use App\Filament\Resources\PrazosPagamento\PrazoPagamentoResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPrazoPagamento extends EditRecord
{
    protected static string $resource = PrazoPagamentoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}
