<?php

namespace App\Filament\Resources\FechamentosCaixa\Pages;

use App\Enums\StatusFechamentoCaixa;
use App\Filament\Resources\FechamentosCaixa\FechamentoCaixaResource;
use App\Models\FechamentoCaixa;
use App\Models\SessaoCaixa;
use App\Services\FechamentoCaixaService;
use Filament\Resources\Pages\CreateRecord;

class CreateFechamentoCaixa extends CreateRecord
{
    protected static string $resource = FechamentoCaixaResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['status'] = StatusFechamentoCaixa::Rascunho;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var FechamentoCaixa $record */
        $record = $this->getRecord();

        app(FechamentoCaixaService::class)->criarOuAtualizarRascunho(
            SessaoCaixa::findOrFail($record->sessao_caixa_id),
            $record,
        );
    }
}
