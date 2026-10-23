<?php

namespace App\Filament\Resources\FechamentosCaixa\Support;

use App\Enums\StatusFechamentoCaixa;
use App\Exceptions\FinanceiroException;
use App\Models\FechamentoCaixa;
use App\Services\FechamentoCaixaService;
use App\Services\Financeiro\ContabilizacaoFechamentoService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Volta o fechamento para Rascunho. Com a sessão contabilizada, estorna a
 * contabilização na mesma transação (motivo obrigatório); se algum recebível
 * já foi liquidado, a reabertura é bloqueada.
 */
class ReabrirFechamentoAction
{
    public static function make(string $name = 'reabrirFechamento'): Action
    {
        return Action::make($name)
            ->label('Reabrir fechamento')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (FechamentoCaixa $record): bool => $record->status === StatusFechamentoCaixa::Confirmado)
            ->modalHeading('Reabrir fechamento')
            ->modalDescription(fn (FechamentoCaixa $record): string => app(ContabilizacaoFechamentoService::class)->contabilizada($record->sessaoCaixa)
                ? 'Estorna a contabilização desta sessão nas contas e volta o fechamento para Rascunho, permitindo corrigir a contagem.'
                : 'Volta o fechamento para Rascunho, permitindo corrigir a contagem de notas/moedas e maquininhas.')
            ->schema(fn (FechamentoCaixa $record): array => app(ContabilizacaoFechamentoService::class)->contabilizada($record->sessaoCaixa)
                ? [Textarea::make('motivo')->label('Motivo do estorno')->required()->minLength(5)]
                : [])
            ->action(function (FechamentoCaixa $record, array $data, FechamentoCaixaService $service, ContabilizacaoFechamentoService $contabilizacao, Action $action): void {
                try {
                    DB::transaction(function () use ($record, $data, $service, $contabilizacao): void {
                        if ($contabilizacao->contabilizada($record->sessaoCaixa)) {
                            $contabilizacao->estornar($record->sessaoCaixa, $data['motivo'] ?? '');
                        }

                        $service->reabrir($record);
                    });
                } catch (ValidationException|FinanceiroException $e) {
                    $record->refresh();

                    Notification::make()
                        ->title('Reabertura bloqueada')
                        ->body($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title('Fechamento reaberto para edição.')
                    ->success()
                    ->send();
            });
    }
}
