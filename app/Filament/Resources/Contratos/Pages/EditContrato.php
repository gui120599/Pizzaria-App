<?php

namespace App\Filament\Resources\Contratos\Pages;

use App\Enums\StatusContrato;
use App\Filament\Resources\Contratos\ContratoResource;
use App\Filament\Resources\Contratos\Support\GerarLancamentoAgoraAction;
use App\Filament\Resources\Contratos\Support\PreparaContrato;
use App\Models\Contrato;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditContrato extends EditRecord
{
    use PreparaContrato;

    protected static string $resource = ContratoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GerarLancamentoAgoraAction::make()
                ->visible(fn (Contrato $record): bool => $record->status === StatusContrato::Ativo),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->validarContrato($data);
    }
}
