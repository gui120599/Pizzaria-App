<?php

namespace App\Support;

use App\Enums\Ponto\AbrangenciaFeriado;
use Carbon\CarbonImmutable;

/**
 * Feriados nacionais (Leis 662/1949, 6.802/1980 e 14.759/2023) e os pontos
 * facultativos nacionais ligados à Páscoa. Carnaval e Corpus Christi entram
 * como facultativos: em muitos municípios são feriado por lei local, e aí
 * basta mudar a abrangência no cadastro.
 *
 * A Páscoa é calculada pelo algoritmo de Meeus/Jones/Butcher (sem depender
 * da extensão `calendar` do PHP).
 */
final class FeriadosNacionais
{
    /** @var array<string, string> 'mm-dd' => nome */
    private const FIXOS = [
        '01-01' => 'Confraternização Universal',
        '04-21' => 'Tiradentes',
        '05-01' => 'Dia do Trabalho',
        '09-07' => 'Independência do Brasil',
        '10-12' => 'Nossa Senhora Aparecida',
        '11-02' => 'Finados',
        '11-15' => 'Proclamação da República',
        '11-20' => 'Dia Nacional de Zumbi e da Consciência Negra',
        '12-25' => 'Natal',
    ];

    /**
     * @return array<int, array{data: CarbonImmutable, nome: string, abrangencia: AbrangenciaFeriado, recorrente_anual: bool}>
     */
    public static function doAno(int $ano): array
    {
        $feriados = [];

        foreach (self::FIXOS as $mesDia => $nome) {
            $feriados[] = [
                'data' => CarbonImmutable::parse("{$ano}-{$mesDia}"),
                'nome' => $nome,
                'abrangencia' => AbrangenciaFeriado::Nacional,
                'recorrente_anual' => true,
            ];
        }

        $pascoa = self::pascoa($ano);

        $moveis = [
            [$pascoa->subDays(48), 'Carnaval (segunda-feira)', AbrangenciaFeriado::Facultativo],
            [$pascoa->subDays(47), 'Carnaval (terça-feira)', AbrangenciaFeriado::Facultativo],
            [$pascoa->subDays(2), 'Paixão de Cristo', AbrangenciaFeriado::Nacional],
            [$pascoa->addDays(60), 'Corpus Christi', AbrangenciaFeriado::Facultativo],
        ];

        foreach ($moveis as [$data, $nome, $abrangencia]) {
            $feriados[] = [
                'data' => $data,
                'nome' => $nome,
                'abrangencia' => $abrangencia,
                'recorrente_anual' => false,
            ];
        }

        return $feriados;
    }

    public static function pascoa(int $ano): CarbonImmutable
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

        return CarbonImmutable::create($ano, $mes, $dia)->startOfDay();
    }
}
