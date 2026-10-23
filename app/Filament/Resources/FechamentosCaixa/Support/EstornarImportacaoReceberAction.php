<?php

namespace App\Filament\Resources\FechamentosCaixa\Support;

use App\Exceptions\FinanceiroException;
use App\Models\FechamentoCaixa;
use App\Services\Financeiro\ContabilizacaoFechamentoService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Desfaz a contabilização desta sessão: estorna as movimentações nas contas e
 * cancela/apaga os títulos. Bloqueado se algum recebível de maquininha já foi
 * liquidado (estorne a liquidação antes). Também é feito por
 * "Reabrir fechamento" quando há contabilização.
 */
class EstornarImportacaoReceberAction
{
    public static function make(string $name = 'estornarImportacaoReceber'): Action
    {
        return Action::make($name)
            ->label('Estornar contabilização')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (FechamentoCaixa $record): bool => auth()->user()?->can('estornarImportacaoReceber', $record)
                && app(ContabilizacaoFechamentoService::class)->contabilizada($record->sessaoCaixa))
            ->modalHeading('Estornar contabilização')
            ->modalDescription('Devolve o dinheiro às contas de origem (estorno das movimentações) e cancela os títulos gerados por esta sessão.')
            ->modalSubmitActionLabel('Confirmar estorno')
            ->schema([
                Textarea::make('motivo')->label('Motivo')->required()->minLength(5),
            ])
            ->action(function (FechamentoCaixa $record, array $data, ContabilizacaoFechamentoService $service, Action $action): void {
                try {
                    $apagados = $service->estornar($record->sessaoCaixa, $data['motivo']);
                } catch (ValidationException|FinanceiroException $e) {
                    Notification::make()
                        ->title('Não foi possível estornar a contabilização')
                        ->body($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title($apagados.' título(s) estornado(s)')
                    ->success()
                    ->send();
            });
    }
}
