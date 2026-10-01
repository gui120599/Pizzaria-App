<?php

namespace App\Support;

/**
 * Formata minutos decorridos pro card do Painel de Pedidos: "02 min", "15 min",
 * "1h 12min" — acima de uma hora, "95 min" é bem menos legível de bater o olho
 * do que "1h 35min".
 */
class FormatoDuracao
{
    public static function minutos(int $totalMinutos): string
    {
        $totalMinutos = max(0, $totalMinutos);

        if ($totalMinutos < 60) {
            return sprintf('%02d min', $totalMinutos);
        }

        $horas = intdiv($totalMinutos, 60);
        $minutos = $totalMinutos % 60;

        return $minutos > 0
            ? sprintf('%dh %02dmin', $horas, $minutos)
            : sprintf('%dh', $horas);
    }
}
