<?php

namespace App\Filament\Resources\FechamentosCaixa\Pages;

use App\Filament\Resources\FechamentosCaixa\FechamentoCaixaResource;
use App\Filament\Resources\FechamentosCaixa\Support\ConfirmarFechamentoAction;
use App\Filament\Resources\FechamentosCaixa\Support\EstornarImportacaoReceberAction;
use App\Filament\Resources\FechamentosCaixa\Support\ImportarReceberAction;
use App\Filament\Resources\FechamentosCaixa\Support\ReabrirFechamentoAction;
use App\Filament\Resources\FechamentosCaixa\Support\RecalcularEsperadoAction;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFechamentoCaixa extends EditRecord
{
    protected static string $resource = FechamentoCaixaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RecalcularEsperadoAction::make(),
            ConfirmarFechamentoAction::make(),
            ReabrirFechamentoAction::make(),
            ImportarReceberAction::make(),
            EstornarImportacaoReceberAction::make(),
            DeleteAction::make(),
        ];
    }
}
