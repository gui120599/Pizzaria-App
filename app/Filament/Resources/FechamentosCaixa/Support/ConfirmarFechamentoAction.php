<?php

namespace App\Filament\Resources\FechamentosCaixa\Support;

use App\Enums\StatusFechamentoCaixa;
use App\Models\FechamentoCaixa;
use App\Services\FechamentoCaixaService;
use App\Services\ImportacaoCaixaReceberService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Trava o fechamento pra edição direta (mesmo padrão de Lancamento::Pago) — a
 * única forma de corrigir depois é "Reabrir fechamento" (ReabrirFechamentoAction).
 * Também dispara a importação automática da receita de vendas da sessão pro
 * Contas a Receber (App\Services\ImportacaoCaixaReceberService) — se não houver
 * receita ou a sessão já tiver sido importada antes (reconfirmação depois de um
 * "Reabrir" sem lançamentos pendentes de estorno), simplesmente não gera nada,
 * sem impedir a confirmação.
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
            ->modalDescription('Trava os lançamentos de notas/moedas e maquininhas e gera os lançamentos de receita no Contas a Receber. Pra corrigir depois, use "Reabrir fechamento".')
            ->action(function (FechamentoCaixa $record, FechamentoCaixaService $service, ImportacaoCaixaReceberService $importacao): void {
                $service->confirmar($record);

                $sessao = $record->sessaoCaixa()->first();
                if ($sessao && $importacao->podeImportar($sessao)) {
                    $importacao->importar($sessao);
                }

                Notification::make()
                    ->title('Fechamento confirmado com sucesso!')
                    ->success()
                    ->send();
            });
    }
}
