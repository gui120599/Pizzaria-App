<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * De onde sai o troco de um pagamento: da gaveta (dinheiro) ou por Pix da
 * conta da empresa (baixa o extrato do PIX CNPJ). Padrão por forma de
 * pagamento em opcoes_pagamentos.opcaopag_troco_padrao; o operador pode trocar
 * no PDV. Troco sem forma gravada (legado) vale como dinheiro.
 */
enum FormaTrocoEnum: string implements HasLabel
{
    case Dinheiro = 'dinheiro';
    case Pix = 'pix';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dinheiro => 'Dinheiro (gaveta)',
            self::Pix => 'Pix (conta PIX CNPJ)',
        };
    }

    /** Categoria do fechamento de caixa de onde o troco sai. */
    public function categoriaFechamento(): string
    {
        return match ($this) {
            self::Dinheiro => 'dinheiro',
            self::Pix => 'pix_cnpj',
        };
    }
}
