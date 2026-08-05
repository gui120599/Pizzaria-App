<?php

namespace App\Filament\Resources\Contratos\Support;

use App\Models\Contrato;
use App\Services\ContratoService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Força a geração do lançamento da competência corrente sem esperar o schedule
 * diário (ver App\Console\Commands\ContratosGerarLancamentosMensais). Útil para
 * testar um contrato recém-criado ou recuperar manualmente um mês que não gerou.
 * Reutilizada na tabela e na página de edição, como o padrão de ConfirmarCompraAction.
 */
class GerarLancamentoAgoraAction
{
    public static function make(string $name = 'gerarLancamentoAgora'): Action
    {
        return Action::make($name)
            ->label('Gerar lançamento agora')
            ->icon('heroicon-o-bolt')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Gerar lançamento da competência atual')
            ->modalDescription('Cria o título a pagar deste mês agora, sem esperar o schedule diário. Não duplica se já tiver sido gerado.')
            ->action(function (Contrato $record, ContratoService $service): void {
                $lancamento = $service->gerarLancamentoMensal($record, now());

                if ($lancamento === null) {
                    Notification::make()
                        ->title('Nada a gerar')
                        ->body('O contrato não está ativo/vigente, ou o lançamento desta competência já existe.')
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Lançamento gerado com sucesso!')
                    ->success()
                    ->send();
            });
    }
}
