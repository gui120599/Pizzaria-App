<?php

namespace App\Filament\Resources\Vendas\Tables\Actions;

use App\Exceptions\NfeIoException;
use App\Models\Venda;
use App\Services\Nfe\ReenvioNfeService;
use Filament\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;

/**
 * Consulta na NFe.io o status das vendas selecionadas cuja nota não está
 * confirmada aqui (webhook perdido). Mesma regra do botão individual.
 */
class AtualizarStatusNfeEmLoteBulkAction
{
    public const LIMITE = 50;

    public static function make(): BulkAction
    {
        return BulkAction::make('atualizarStatusNfeEmLote')
            ->label('Atualizar status NFC-e')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('emitir:nfe') ?? false)
            ->requiresConfirmation()
            ->modalHeading('Atualizar o status da NFC-e na NFe.io')
            ->modalDescription('Consulta só as vendas com nota ainda não confirmada. Máximo de '.self::LIMITE.' consultas por vez.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, ReenvioNfeService $reenvio): void {
                $consultadas = 0;
                $mudaram = 0;
                $puladas = [];
                $falhas = [];

                foreach ($records as $venda) {
                    /** @var Venda $venda */
                    if (! $reenvio->podeAtualizarStatus($venda)) {
                        $puladas[] = "#{$venda->id}: sem nota pendente de confirmação.";

                        continue;
                    }

                    if ($consultadas + count($falhas) >= self::LIMITE) {
                        $puladas[] = "#{$venda->id}: limite de ".self::LIMITE.' consultas por vez; repita para o restante.';

                        continue;
                    }

                    $antes = $venda->venda_status_nfe;

                    try {
                        $depois = $reenvio->atualizarStatus($venda);
                        $consultadas++;
                        $mudaram += $depois !== $antes ? 1 : 0;
                    } catch (NfeIoException $e) {
                        $falhas[] = "#{$venda->id}: {$e->getMessage()}";
                    }

                    usleep(100_000);
                }

                RelatorioEmLote::notificar('Status da NFC-e', $consultadas, "consultada(s), {$mudaram} com status novo", $puladas, $falhas);
            });
    }
}
