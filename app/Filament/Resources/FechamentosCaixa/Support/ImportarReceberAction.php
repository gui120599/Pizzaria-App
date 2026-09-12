<?php

namespace App\Filament\Resources\FechamentosCaixa\Support;

use App\Models\FechamentoCaixa;
use App\Services\ImportacaoCaixaReceberService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Importa a receita de vendas desta sessão (fechamento Confirmado) pro Contas
 * a Receber — 1 lançamento por opção de pagamento, já Pago. Visível só quando
 * ainda não importado (cobre tanto o fluxo normal, onde ConfirmarFechamentoAction
 * já importa automaticamente ao confirmar, quanto o backfill de fechamentos
 * confirmados antes desta feature existir).
 */
class ImportarReceberAction
{
    public static function make(string $name = 'importarReceber'): Action
    {
        return Action::make($name)
            ->label('Importar p/ Contas a Receber')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (FechamentoCaixa $record): bool => auth()->user()?->can('importarReceber', $record)
                && app(ImportacaoCaixaReceberService::class)->podeImportar($record->sessaoCaixa))
            ->requiresConfirmation()
            ->modalHeading('Importar sessão para o Contas a Receber')
            ->modalDescription('Gera um lançamento a receber (já Pago) por opção de pagamento com receita de vendas nesta sessão.')
            ->modalSubmitActionLabel('Importar')
            ->action(function (FechamentoCaixa $record, ImportacaoCaixaReceberService $service, Action $action): void {
                try {
                    $lancamentos = $service->importar($record->sessaoCaixa);
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Não foi possível importar')
                        ->body(collect($e->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title($lancamentos->count().' lançamento(s) gerado(s) no Contas a Receber')
                    ->success()
                    ->send();
            });
    }
}
