<?php

namespace App\Enums;

enum MovTipoEnum: string
{
    case ENTRADA = 'entrada';
    case SAIDA = 'saida';
    case AJUSTE = 'ajuste';
    case PRODUCAO = 'producao';

    public function label(): string
    {
        return match($this) {
            self::ENTRADA => 'Entrada',
            self::SAIDA => 'Saída',
            self::AJUSTE => 'Ajuste',
            self::PRODUCAO => 'Produção',
        };
    }
}