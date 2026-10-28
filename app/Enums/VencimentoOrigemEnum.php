<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** De onde vieram os vencimentos das parcelas geradas na confirmação da compra. */
enum VencimentoOrigemEnum: string implements HasLabel
{
    case DuplicatasNf = 'duplicatas_nf';
    case PrazoEmissao = 'prazo_emissao';
    case PrazoEntrada = 'prazo_entrada';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::DuplicatasNf => 'Duplicatas da NF-e',
            self::PrazoEmissao => 'Prazo contado da emissão',
            self::PrazoEntrada => 'Prazo contado da entrada',
            self::Manual => 'Manual',
        };
    }

    /**
     * Vencimentos que dependem da data de entrada no estoque: só esses fazem
     * sentido ser deslocados quando a entrada é corrigida. Compra antiga
     * (origem nula) conta como prazo da entrada, o comportamento de sempre.
     */
    public static function dependeDaEntrada(?self $origem): bool
    {
        return $origem === null || $origem === self::PrazoEntrada || $origem === self::Manual;
    }
}
