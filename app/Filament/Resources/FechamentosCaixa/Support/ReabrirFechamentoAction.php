<?php

namespace App\Filament\Resources\FechamentosCaixa\Support;

use App\Enums\StatusFechamentoCaixa;
use App\Models\FechamentoCaixa;
use App\Services\FechamentoCaixaService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ReabrirFechamentoAction
{
    public static function make(string $name = 'reabrirFechamento'): Action
    {
        return Action::make($name)
            ->label('Reabrir fechamento')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (FechamentoCaixa $record): bool => $record->status === StatusFechamentoCaixa::Confirmado)
            ->requiresConfirmation()
            ->modalHeading('Reabrir fechamento')
            ->modalDescription('Volta o fechamento para Rascunho, permitindo corrigir a contagem de notas/moedas e maquininhas.')
            ->action(function (FechamentoCaixa $record, FechamentoCaixaService $service): void {
                $service->reabrir($record);

                Notification::make()
                    ->title('Fechamento reaberto para edição.')
                    ->success()
                    ->send();
            });
    }
}
