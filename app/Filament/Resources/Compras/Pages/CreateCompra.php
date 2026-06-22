<?php

namespace App\Filament\Resources\Compras\Pages;

use App\Filament\Resources\Compras\CompraResource;
use App\Services\CompraService;
use Filament\Resources\Pages\CreateRecord;

class CreateCompra extends CreateRecord
{
    protected static string $resource = CompraResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['compra_user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        app(CompraService::class)->recalcularTotais($this->record->load('itens'));
    }

    /** Após criar o cabeçalho, vai para a edição para adicionar os itens. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
