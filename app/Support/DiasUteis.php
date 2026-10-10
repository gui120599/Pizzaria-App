<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Dias úteis bancários: segunda a sexta, fora os feriados nacionais e os
 * dias sem expediente bancário (Carnaval, Sexta-feira Santa e Corpus Christi).
 * Feriados estaduais/municipais não entram.
 */
class DiasUteis
{
    /** Primeiro dia útil depois de $data. */
    public static function proximo(CarbonInterface $data): Carbon
    {
        $dia = Carbon::instance($data)->startOfDay()->addDay();

        while (! self::ehDiaUtil($dia)) {
            $dia->addDay();
        }

        return $dia;
    }

    public static function ehDiaUtil(CarbonInterface $data): bool
    {
        return ! $data->isWeekend() && ! in_array($data->format('Y-m-d'), self::feriados($data->year), true);
    }

    /** @return list<string> datas Y-m-d */
    public static function feriados(int $ano): array
    {
        $pascoa = self::pascoa($ano);

        $moveis = [
            $pascoa->copy()->subDays(48),
            $pascoa->copy()->subDays(47),
            $pascoa->copy()->subDays(2),
            $pascoa->copy()->addDays(60),
        ];

        $fixos = ['01-01', '04-21', '05-01', '09-07', '10-12', '11-02', '11-15', '12-25'];

        if ($ano >= 2024) {
            $fixos[] = '11-20';
        }

        return [
            ...array_map(fn (string $diaMes): string => "{$ano}-{$diaMes}", $fixos),
            ...array_map(fn (Carbon $dia): string => $dia->format('Y-m-d'), $moveis),
        ];
    }

    /** Domingo de Páscoa (algoritmo de Meeus/Jones/Butcher). */
    private static function pascoa(int $ano): Carbon
    {
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($ano, $mes, $dia)->startOfDay();
    }
}
