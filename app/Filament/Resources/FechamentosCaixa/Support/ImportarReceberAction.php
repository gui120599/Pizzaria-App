<?php

namespace App\Filament\Resources\FechamentosCaixa\Support;

use App\Exceptions\FinanceiroException;
use App\Models\FechamentoCaixa;
use App\Services\Financeiro\ContabilizacaoFechamentoService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Contabiliza uma sessão com fechamento Confirmado que ainda não foi
 * contabilizada (backfill de fechamentos confirmados antes da contabilização
 * nas contas existir). No fluxo normal quem contabiliza é a confirmação
 * (ConfirmarFechamentoAction). Sem recolhimento: o dinheiro já foi tratado.
 */
class ImportarReceberAction
{
    public static function make(string $name = 'importarReceber'): Action
    {
        return Action::make($name)
            ->label('Contabilizar nas contas')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (FechamentoCaixa $record): bool => auth()->user()?->can('importarReceber', $record)
                && app(ContabilizacaoFechamentoService::class)->podeContabilizar($record->sessaoCaixa))
            ->requiresConfirmation()
            ->modalHeading('Contabilizar sessão nas contas financeiras')
            ->modalDescription('Gera os títulos a receber da sessão e lança o dinheiro e o Pix nas contas; cartão e Pix de maquininha viram recebíveis pendentes até a data de repasse.')
            ->modalSubmitActionLabel('Contabilizar')
            ->action(function (FechamentoCaixa $record, ContabilizacaoFechamentoService $service, Action $action): void {
                try {
                    $lancamentos = $service->contabilizar($record->sessaoCaixa);
                } catch (ValidationException|FinanceiroException $e) {
                    Notification::make()
                        ->title('Não foi possível contabilizar')
                        ->body($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title($lancamentos->count().' título(s) gerado(s)')
                    ->success()
                    ->send();
            });
    }
}
