<?php

namespace App\Filament\Resources\Compras\Pages;

use App\Filament\Resources\Compras\CompraResource;
use App\Models\Compra;
use App\Services\CompraService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditCompra extends EditRecord
{
    protected static string $resource = CompraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirmar')
                ->label('Confirmar compra')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record instanceof Compra && $this->record->isRascunho())
                ->requiresConfirmation()
                ->modalDescription('Gera as entradas de estoque e recalcula o custo médio. Esta ação não pode ser desfeita.')
                ->action(function () {
                    try {
                        app(CompraService::class)->confirmar($this->record);
                        Notification::make()->title('Compra confirmada')->body('Estoque atualizado.')->success()->send();
                        $this->redirect(static::getResource()::getUrl('index'));
                    } catch (ValidationException $e) {
                        Notification::make()->title('Não foi possível confirmar')->body(collect($e->errors())->flatten()->first())->danger()->send();
                    }
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
