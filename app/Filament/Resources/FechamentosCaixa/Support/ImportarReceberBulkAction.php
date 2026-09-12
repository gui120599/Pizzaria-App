<?php

namespace App\Filament\Resources\FechamentosCaixa\Support;

use App\Models\FechamentoCaixa;
use App\Services\ImportacaoCaixaReceberService;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Importa em lote a receita de vendas dos fechamentos Confirmados selecionados
 * pro Contas a Receber — útil pra backfill de vários fechamentos confirmados
 * antes desta feature existir. Cada sessão roda numa transação independente
 * (ImportacaoCaixaReceberService::importar); a falha de uma não impede as
 * demais, mesmo padrão de ImportarXmlAction.
 */
class ImportarReceberBulkAction
{
    public static function make(string $name = 'importarReceberBulk'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Importar p/ Contas a Receber')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Importar sessões selecionadas para o Contas a Receber')
            ->modalDescription('Sessões já importadas, não confirmadas ou sem receita de vendas são ignoradas.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, ImportacaoCaixaReceberService $service): void {
                $importados = 0;
                $ignorados = 0;
                $falhas = [];

                /** @var FechamentoCaixa $record */
                foreach ($records as $record) {
                    if (! $service->podeImportar($record->sessaoCaixa)) {
                        $ignorados++;

                        continue;
                    }

                    try {
                        $service->importar($record->sessaoCaixa);
                        $importados++;
                    } catch (ValidationException $e) {
                        $falhas[] = "Sessão #{$record->sessao_caixa_id}: ".collect($e->errors())->flatten()->first();
                    }
                }

                if ($importados > 0) {
                    Notification::make()
                        ->title($importados.' sessão(ões) importada(s)')
                        ->success()
                        ->send();
                }

                if ($ignorados > 0 && $falhas === []) {
                    Notification::make()
                        ->title($ignorados.' sessão(ões) ignorada(s)')
                        ->body('Já importadas, sem fechamento confirmado ou sem receita de vendas.')
                        ->warning()
                        ->send();
                }

                if ($falhas !== []) {
                    Notification::make()
                        ->title(count($falhas).' sessão(ões) não importada(s)')
                        ->body(implode(' | ', $falhas))
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }
}
