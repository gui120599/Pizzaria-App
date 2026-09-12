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
            ->action(function (FechamentoCaixa $record, FechamentoCaixaService $service, Action $action): void {
                // Reconfirmar depois de reaberto recalcularia o snapshot do esperado
                // (RecalcularEsperadoAction), mas os lançamentos a receber já
                // importados ficariam com valores defasados — estornar a
                // importação primeiro (EstornarImportacaoReceberAction).
                if ($record->sessaoCaixa->lancamentos()->exists()) {
                    Notification::make()
                        ->title('Reabertura bloqueada')
                        ->body('Existe importação para o Contas a Receber vinculada a esta sessão. Estorne a importação antes de reabrir.')
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                $service->reabrir($record);

                Notification::make()
                    ->title('Fechamento reaberto para edição.')
                    ->success()
                    ->send();
            });
    }
}
