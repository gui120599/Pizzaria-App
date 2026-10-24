<?php

namespace App\Filament\Resources\Vendas\Tables\Actions;

use Filament\Notifications\Notification;

/** Resumo final das ações em lote da tabela de vendas: feitas, puladas (com motivo) e com erro. */
class RelatorioEmLote
{
    /**
     * @param  array<int, string>  $puladas
     * @param  array<int, string>  $falhas
     */
    public static function notificar(string $titulo, int $feitas, string $verbo, array $puladas, array $falhas): void
    {
        $linhas = ["{$feitas} {$verbo}."];

        if ($puladas !== []) {
            $linhas[] = count($puladas).' pulada(s):';
            array_push($linhas, ...array_map(fn (string $linha): string => '• '.$linha, array_slice($puladas, 0, 10)));
            $linhas[] = count($puladas) > 10 ? '… e mais '.(count($puladas) - 10).'.' : '';
        }

        if ($falhas !== []) {
            $linhas[] = count($falhas).' com erro:';
            array_push($linhas, ...array_map(fn (string $linha): string => '• '.$linha, array_slice($falhas, 0, 10)));
        }

        $notificacao = Notification::make()
            ->title($titulo)
            ->body(implode("\n", array_filter($linhas, fn (string $linha): bool => $linha !== '')));

        ($falhas !== [] ? $notificacao->danger()->persistent() : ($puladas !== [] ? $notificacao->warning() : $notificacao->success()))->send();
    }
}
