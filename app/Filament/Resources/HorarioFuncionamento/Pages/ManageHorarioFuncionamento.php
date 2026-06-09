<?php

namespace App\Filament\Resources\HorarioFuncionamento\Pages;

use App\Filament\Resources\HorarioFuncionamento\HorarioFuncionamentoResource;
use Filament\Resources\Pages\ManageRecords;

class ManageHorarioFuncionamento extends ManageRecords
{
    protected static string $resource = HorarioFuncionamentoResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
