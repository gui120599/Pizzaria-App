<?php

namespace App\Enums;

/** Efeito de uma operação fiscal de entrada sobre o estoque (ótica da pizzaria). */
enum EfeitoEstoqueEnum: string
{
    case ENTRADA = 'entrada';
    case SAIDA = 'saida';
    case NENHUM = 'nenhum';
    case A_DEFINIR = 'a_definir';

    public function label(): string
    {
        return match ($this) {
            self::ENTRADA => 'Entrada',
            self::SAIDA => 'Saída',
            self::NENHUM => 'Sem movimentação',
            self::A_DEFINIR => 'Depende da operação',
        };
    }
}
