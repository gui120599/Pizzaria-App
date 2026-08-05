<?php

namespace App\Filament\Resources\FechamentosCaixa\Support;

use App\Enums\StatusFechamentoCaixa;
use App\Models\FechamentoCaixa;
use App\Services\FechamentoCaixaService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Trava o fechamento pra edição direta (mesmo padrão de Lancamento::Pago) — a
 * única forma de corrigir depois é "Reabrir fechamento" (ReabrirFechamentoAction).
 */
class ConfirmarFechamentoAction
{
    public static function make(string $name = 'confirmarFechamento'): Action
    {
        return Action::make($name)
            ->label('Confirmar fechamento')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (FechamentoCaixa $record): bool => $record->status === StatusFechamentoCaixa::Rascunho)
            ->requiresConfirmation()
            ->modalHeading('Confirmar fechamento de caixa')
            ->modalDescription('Trava os lançamentos de notas/moedas e maquininhas. Pra corrigir depois, use "Reabrir fechamento".')
            ->action(function (FechamentoCaixa $record, FechamentoCaixaService $service): void {
                $service->confirmar($record);

                Notification::make()
                    ->title('Fechamento confirmado com sucesso!')
                    ->success()
                    ->send();
            });
    }
}
