<?php

namespace App\Filament\Resources\FechamentosCaixa\Support;

use App\Models\FechamentoCaixa;
use App\Services\ImportacaoCaixaReceberService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Desfaz a importação desta sessão pro Contas a Receber — apaga os lançamentos
 * gerados (e seus pagamentos, via cascade). Necessário antes de reabrir um
 * fechamento já importado (ver ReabrirFechamentoAction).
 */
class EstornarImportacaoReceberAction
{
    public static function make(string $name = 'estornarImportacaoReceber'): Action
    {
        return Action::make($name)
            ->label('Estornar importação')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (FechamentoCaixa $record): bool => auth()->user()?->can('estornarImportacaoReceber', $record)
                && $record->sessaoCaixa->lancamentos()->exists())
            ->requiresConfirmation()
            ->modalHeading('Estornar importação')
            ->modalDescription('Apaga os lançamentos a receber gerados a partir desta sessão. Só é possível se nenhum deles tiver pagamento além da baixa automática da importação.')
            ->modalSubmitActionLabel('Confirmar estorno')
            ->action(function (FechamentoCaixa $record, ImportacaoCaixaReceberService $service, Action $action): void {
                try {
                    $apagados = $service->estornarImportacao($record->sessaoCaixa);
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Não foi possível estornar a importação')
                        ->body(collect($e->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title($apagados.' lançamento(s) apagado(s)')
                    ->success()
                    ->send();
            });
    }
}
