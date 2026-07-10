<?php

namespace App\Filament\Resources\Compras\Pages;

use App\Filament\Resources\Compras\CompraResource;
use App\Filament\Resources\Compras\Support\ConfirmarCompraAction;
use App\Models\Compra;
use App\Services\CompraService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompra extends EditRecord
{
    protected static string $resource = CompraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ConfirmarCompraAction::make()
                ->after(function (): void {
                    $this->redirect(static::getResource()::getUrl('index'));
                }),
            DeleteAction::make()
                ->visible(fn (): bool => $this->record instanceof Compra && $this->record->isRascunho()),
        ];
    }

    protected function afterSave(): void
    {
        app(CompraService::class)->recalcularTotais($this->record->load('itens'));
    }
}
