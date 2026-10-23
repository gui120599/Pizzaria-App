<?php

namespace App\Filament\Resources\FechamentosCaixa\Support;

use App\Exceptions\FinanceiroException;
use App\Models\FechamentoCaixa;
use App\Services\Financeiro\ContabilizacaoFechamentoService;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Contabiliza em lote os fechamentos Confirmados selecionados. Cada sessão
 * roda numa transação independente (ContabilizacaoFechamentoService::contabilizar);
 * a falha de uma não impede as demais.
 */
class ImportarReceberBulkAction
{
    public static function make(string $name = 'importarReceberBulk'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Contabilizar nas contas')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Contabilizar sessões selecionadas')
            ->modalDescription('Sessões já contabilizadas, não confirmadas ou sem receita de vendas são ignoradas.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, ContabilizacaoFechamentoService $service): void {
                $contabilizados = 0;
                $ignorados = 0;
                $falhas = [];

                /** @var FechamentoCaixa $record */
                foreach ($records as $record) {
                    if (! $service->podeContabilizar($record->sessaoCaixa)) {
                        $ignorados++;

                        continue;
                    }

                    try {
                        $service->contabilizar($record->sessaoCaixa);
                        $contabilizados++;
                    } catch (ValidationException|FinanceiroException $e) {
                        $falhas[] = "Sessão #{$record->sessao_caixa_id}: ".($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage());
                    }
                }

                if ($contabilizados > 0) {
                    Notification::make()
                        ->title($contabilizados.' sessão(ões) contabilizada(s)')
                        ->success()
                        ->send();
                }

                if ($ignorados > 0 && $falhas === []) {
                    Notification::make()
                        ->title($ignorados.' sessão(ões) ignorada(s)')
                        ->body('Já contabilizadas, sem fechamento confirmado ou sem receita de vendas.')
                        ->warning()
                        ->send();
                }

                if ($falhas !== []) {
                    Notification::make()
                        ->title(count($falhas).' sessão(ões) não contabilizada(s)')
                        ->body(implode(' | ', $falhas))
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }
}
