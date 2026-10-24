<?php

namespace App\Filament\Resources\Vendas\Tables\Actions;

use App\Exceptions\NfeIoException;
use App\Models\Venda;
use App\Services\Nfe\ReenvioNfeService;
use Filament\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;

/**
 * Envia/reenvia a NFC-e das vendas selecionadas, com as mesmas regras do botão
 * individual (ReenvioNfeService). Uma chamada HTTP por venda, por isso o teto
 * por rodada; a falha de uma não interrompe as outras.
 */
class EnviarNfeEmLoteBulkAction
{
    public const LIMITE = 25;

    public static function make(): BulkAction
    {
        return BulkAction::make('enviarNfeEmLote')
            ->label('Enviar NFC-e')
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->authorize(fn (): bool => auth()->user()?->can('emitir:nfe') ?? false)
            ->requiresConfirmation()
            ->modalHeading('Enviar NFC-e das vendas selecionadas')
            ->modalDescription('Envia só as que podem receber nota (sem nota, com falha ou nota cancelada). As demais são puladas, com o motivo. Máximo de '.self::LIMITE.' envios por vez.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, ReenvioNfeService $reenvio): void {
                $enviadas = 0;
                $puladas = [];
                $falhas = [];

                foreach ($records as $venda) {
                    /** @var Venda $venda */
                    if ($motivo = $reenvio->motivoBloqueio($venda)) {
                        $puladas[] = "#{$venda->id}: {$motivo}";

                        continue;
                    }

                    if ($enviadas + count($falhas) >= self::LIMITE) {
                        $puladas[] = "#{$venda->id}: limite de ".self::LIMITE.' envios por vez; repita para o restante.';

                        continue;
                    }

                    try {
                        $reenvio->reenviar($venda);
                        $enviadas++;
                    } catch (NfeIoException $e) {
                        $falhas[] = "#{$venda->id}: {$e->getMessage()}";
                    }

                    usleep(150_000);
                }

                RelatorioEmLote::notificar('NFC-e', $enviadas, 'enviada(s)', $puladas, $falhas);
            });
    }
}
