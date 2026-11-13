<?php

namespace App\Enums\Compras;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/** Quando o contas a pagar de uma compra importada é considerado pago. */
enum RegraBaixaCompra: string implements HasDescription, HasLabel
{
    case AutomaticaNaEmissao = 'automatica_na_emissao';
    case AutomaticaNoVencimento = 'automatica_no_vencimento';
    case PorConciliacao = 'por_conciliacao';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::AutomaticaNaEmissao => 'Automática na emissão (PIX/dinheiro na entrega)',
            self::AutomaticaNoVencimento => 'Automática no vencimento (débito automático)',
            self::PorConciliacao => 'Por conciliação com o extrato',
            self::Manual => 'Manual (confirmação em lote)',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::AutomaticaNaEmissao => 'O título nasce pago, na data de emissão, pela conta e forma do fornecedor.',
            self::AutomaticaNoVencimento => 'Um comando diário dá baixa nas parcelas no dia do vencimento. Use só para débito automático.',
            self::PorConciliacao => 'A baixa acontece quando o débito aparecer no extrato do banco (ainda não disponível).',
            self::Manual => 'As parcelas ficam abertas para confirmação manual.',
        };
    }
}
