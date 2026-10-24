<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Tipos de correção de lançamento do Relatório de Sessão de Caixa (ver CorrecaoPagamentoService). */
enum TipoCorrecaoPagamentoEnum: string implements HasLabel
{
    case Forma = 'forma';
    case Divisao = 'divisao';
    case FormaFiado = 'forma_fiado';
    case FormaTroco = 'forma_troco';
    case ClienteVenda = 'cliente_venda';

    public function getLabel(): string
    {
        return match ($this) {
            self::Forma => 'Troca de forma de pagamento',
            self::Divisao => 'Divisão de pagamento',
            self::FormaFiado => 'Troca de forma do fiado recebido',
            self::FormaTroco => 'Troca da forma do troco',
            self::ClienteVenda => 'Troca do cliente da venda',
        };
    }
}
