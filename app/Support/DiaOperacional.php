<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Dia operacional da loja: antes de config('operacao.virada_dia') ainda vale o
 * dia anterior (o turno de sábado que termina 01:30 de domingo é de sábado).
 *
 * Não confundir com App\Support\JanelaOperacional, que vira no horário de
 * abertura da loja e jogaria a abertura das 16h no dia anterior.
 */
final class DiaOperacional
{
    /** Dia operacional (00:00) a que o instante pertence. */
    public static function de(?CarbonInterface $momento = null): CarbonImmutable
    {
        $momento = CarbonImmutable::instance($momento ?? now());

        return ($momento->lessThan(self::viradaDoDiaCivil($momento)) ? $momento->subDay() : $momento)->startOfDay();
    }

    /** Primeiro instante do dia operacional (o dia na hora da virada). */
    public static function inicio(CarbonInterface $dia): CarbonImmutable
    {
        return self::viradaDoDiaCivil(CarbonImmutable::instance($dia));
    }

    /** Fim exclusivo do dia operacional (a virada do dia seguinte). */
    public static function fim(CarbonInterface $dia): CarbonImmutable
    {
        return self::inicio($dia)->addDay();
    }

    /** O instante cai dentro do dia operacional informado. */
    public static function contem(CarbonInterface $dia, CarbonInterface $momento): bool
    {
        return $momento->greaterThanOrEqualTo(self::inicio($dia)) && $momento->lessThan(self::fim($dia));
    }

    /**
     * Instante de um horário "H:i" dentro do dia operacional: antes da virada
     * cai no dia civil seguinte (01:30 do dia operacional de sábado = domingo 01:30).
     */
    public static function momentoNoDia(CarbonInterface $dia, string $hora): CarbonImmutable
    {
        $momento = CarbonImmutable::instance($dia)->startOfDay()->setTimeFromTimeString($hora);

        return $momento->lessThan(self::inicio($dia)) ? $momento->addDay() : $momento;
    }

    private static function viradaDoDiaCivil(CarbonImmutable $data): CarbonImmutable
    {
        return $data->startOfDay()->setTimeFromTimeString((string) config('operacao.virada_dia', '05:00'));
    }
}
