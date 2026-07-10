<?php

namespace App\Filament\Resources\PromocoesRelampago\Pages;

use App\Filament\Resources\PromocoesRelampago\PromocaoRelampagoResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPromocaoRelampago extends EditRecord
{
    protected static string $resource = PromocaoRelampagoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}
