<?php

namespace App\Enums;

/** Como o custo de uma operação fiscal de entrada é valorado no estoque. */
enum PoliticaCustoEnum: string
{
    case NORMAL = 'normal';
    case ZERO = 'zero';
    case ORIGINAL = 'original';
    case AJUSTE_VALOR = 'ajuste_valor';
    case NENHUMA = 'nenhuma';
    case A_DEFINIR = 'a_definir';

    public function label(): string
    {
        return match ($this) {
            self::NORMAL => 'Custo de aquisição (WAC)',
            self::ZERO => 'Custo zero (dilui o WAC)',
            self::ORIGINAL => 'Valor da compra original',
            self::AJUSTE_VALOR => 'Ajuste de valor sobre a entrada original',
            self::NENHUMA => 'Não se aplica',
            self::A_DEFINIR => 'Depende da operação',
        };
    }
}
