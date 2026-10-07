<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FormaPagamento: string implements HasLabel
{
    case Dinheiro = 'dinheiro';
    case Pix = 'pix';
    case PixCnpj = 'pix_cnpj';
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
            self::PixCnpj => 'PIX CNPJ',
            self::CartaoCredito => 'Cartão de Crédito',
            self::CartaoDebito => 'Cartão de Débito',
            self::Boleto => 'Boleto',
            self::Transferencia => 'Transferência',
            self::Compensacao => 'Compensação',
        };
    }

    /**
     * Recebimento que entra no caixa da sessão (gaveta, maquininha ou Pix) e
     * por isso conta no esperado do fechamento. Boleto, transferência e
     * compensação quitam o título sem passar pelo caixa.
     */
    public function entraNoCaixa(): bool
    {
        return match ($this) {
            self::Dinheiro, self::Pix, self::PixCnpj, self::CartaoCredito, self::CartaoDebito => true,
            self::Boleto, self::Transferencia, self::Compensacao => false,
        };
    }

    /**
     * Tipo de transação na maquininha (define a taxa da adquirente). Pix aqui
     * é o da maquininha; PIX CNPJ cai direto na conta e não tem maquininha.
     */
    public function tipoMaquininha(): ?TipoPagamentoMaquininhaEnum
    {
        return match ($this) {
            self::CartaoDebito => TipoPagamentoMaquininhaEnum::Debito,
            self::CartaoCredito => TipoPagamentoMaquininhaEnum::Credito,
            self::Pix => TipoPagamentoMaquininhaEnum::Pix,
            default => null,
        };
    }
}
