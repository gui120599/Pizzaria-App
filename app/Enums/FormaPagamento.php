<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FormaPagamento: string implements HasLabel
{
    case Dinheiro = 'dinheiro';
    case Pix = 'pix';
    case CartaoCredito = 'cartao_credito';
    case CartaoDebito = 'cartao_debito';
    case Boleto = 'boleto';
    case Transferencia = 'transferencia';
    case Compensacao = 'compensacao';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dinheiro => 'Dinheiro',
            self::Pix => 'Pix',
            self::CartaoCredito => 'Cartão de Crédito',
            self::CartaoDebito => 'Cartão de Débito',
            self::Boleto => 'Boleto',
            self::Transferencia => 'Transferência',
            self::Compensacao => 'Compensação',
        };
    }
}
