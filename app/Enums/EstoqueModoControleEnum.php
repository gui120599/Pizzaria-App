<?php

namespace App\Enums;

enum EstoqueModoControleEnum: string
{
    case BLOQUEAR = 'bloquear';
    case AVISAR = 'avisar';
    case NAO_CONTROLAR = 'nao_controlar';

    public function label(): string
    {
        return match($this) {
            self::BLOQUEAR => 'Bloquear Venda Sem Estoque',
            self::AVISAR => 'Apenas Avisar',
            self::NAO_CONTROLAR => 'Não Controlar Estoque',
        };
    }

    public function bloqueiaVenda(): bool
    {
        return $this === self::BLOQUEAR;
    }

    public function apenasAvisa(): bool
    {
        return $this === self::AVISAR;
    }
}