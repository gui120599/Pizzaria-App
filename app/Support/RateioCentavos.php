<?php

namespace App\Support;

/**
 * Utilitários de rateio monetário em centavos, sem perder nem inventar
 * centavos por causa de arredondamento.
 */
final class RateioCentavos
{
    /** Fatia $valor em $n partes de centavos; o resto vai para as primeiras fatias. */
    public static function fatiaCentavos(float $valor, int $n, int $idx): int
    {
        $cents = (int) round($valor * 100);
        $base = intdiv($cents, $n);
        $resto = $cents % $n;

        return $base + ($idx < $resto ? 1 : 0);
    }

    /**
     * Distribui $totalCents proporcionalmente a $pesos, sem perder centavos:
     * o resto do arredondamento vai para os maiores pesos.
     *
     * @param  array<int, int>  $pesos
     * @return array<int, int>
     */
    public static function ratearProporcional(int $totalCents, array $pesos): array
    {
        $somaPesos = array_sum($pesos);

        if ($somaPesos <= 0 || $totalCents <= 0) {
            return array_fill_keys(array_keys($pesos), 0);
        }

        $rateio = [];
        $distribuido = 0;

        foreach ($pesos as $idx => $peso) {
            $rateio[$idx] = intdiv($totalCents * $peso, $somaPesos);
            $distribuido += $rateio[$idx];
        }

        // Sobra de arredondamento: no máximo count($pesos) - 1 centavos.
        $sobra = $totalCents - $distribuido;
        $ordemPorPeso = array_keys($pesos);
        usort($ordemPorPeso, fn (int $a, int $b) => $pesos[$b] <=> $pesos[$a]);

        foreach ($ordemPorPeso as $idx) {
            if ($sobra <= 0) {
                break;
            }
            $rateio[$idx]++;
            $sobra--;
        }

        return $rateio;
    }
}
