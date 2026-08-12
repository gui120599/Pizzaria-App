<?php

namespace App\Support;

/**
 * Formata custo unitário em R$ pt-BR, mostrando mais casas decimais quando
 * o valor é pequeno o suficiente para sumir com 2 casas (ex.: insumo
 * cotado por litro/grama, como água a R$ 0,00950475).
 */
final class CustoUnitarioFormatter
{
    public static function formatar(float $valor): string
    {
        $casas = ($valor !== 0.0 && abs($valor) < 0.01) ? 8 : 2;

        return 'R$ '.number_format($valor, $casas, ',', '.');
    }
}
