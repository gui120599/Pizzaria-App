<?php

namespace App\Filament\Resources\DispositivosCozinha\Pages;

use App\Filament\Resources\DispositivosCozinha\DispositivoCozinhaResource;
use App\Filament\Resources\DispositivosCozinha\Support\ParearDispositivoAction;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDispositivosCozinha extends ManageRecords
{
    protected static string $resource = DispositivoCozinhaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /** Aberto pelo "Parear" da tabela (replaceMountedAction) com o token recém-gerado. */
    public function qrPareamentoAction(): Action
    {
        return ParearDispositivoAction::qrModal();
    }
}
