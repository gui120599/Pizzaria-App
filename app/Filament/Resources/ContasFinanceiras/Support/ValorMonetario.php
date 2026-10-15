<?php

namespace App\Filament\Resources\ContasFinanceiras\Support;

/**
 * Money::make() pode entregar o valor ainda formatado ("1.234,56"); o mesmo
 * normalizador que as actions do financeiro usam, num lugar só para as telas
 * de contas financeiras e do ponto.
 */
final class ValorMonetario
{
    public static function normalizar(mixed $valor): float
    {
        if ($valor === null || $valor === '') {
            return 0.0;
        }

        if (is_numeric($valor)) {
            return (float) $valor;
        }

        return (float) str_replace(['.', ','], ['', '.'], (string) $valor);
    }

    public static function formatar(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }
}
