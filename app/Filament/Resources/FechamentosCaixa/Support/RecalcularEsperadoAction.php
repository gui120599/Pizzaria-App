<?php

namespace App\Filament\Resources\FechamentosCaixa\Support;

use App\Enums\StatusFechamentoCaixa;
use App\Models\FechamentoCaixa;
use App\Services\FechamentoCaixaService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Refaz o snapshot do esperado a partir de vendas/pagamentos_vendas — útil se
 * uma venda da sessão foi corrigida/cancelada depois do fechamento ter sido
 * criado em rascunho (o esperado não é recalculado automaticamente a cada save
 * porque não depende dos repeaters de notas/maquininhas, só da sessão).
 */
class RecalcularEsperadoAction
{
    public static function make(string $name = 'recalcularEsperado'): Action
    {
        return Action::make($name)
            ->label('Recalcular esperado')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (FechamentoCaixa $record): bool => $record->status === StatusFechamentoCaixa::Rascunho)
            ->requiresConfirmation()
            ->modalDescription('Refaz o total esperado por forma de pagamento a partir das vendas da sessão.')
            ->action(function (FechamentoCaixa $record, FechamentoCaixaService $service): void {
                $service->criarOuAtualizarRascunho($record->sessaoCaixa, $record);

                Notification::make()
                    ->title('Esperado recalculado.')
                    ->success()
                    ->send();
            });
    }
}
