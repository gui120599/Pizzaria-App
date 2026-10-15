<?php

namespace App\Enums\Financeiro;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Por que o dinheiro entrou/saiu da conta.
 */
enum NaturezaMovimentacao: string implements HasColor, HasLabel
{
    case PagamentoLancamento = 'pagamento_lancamento';
    case RecebimentoLancamento = 'recebimento_lancamento';
    case Adiantamento = 'adiantamento';
    case Transferencia = 'transferencia';
    case AjusteSaldo = 'ajuste_saldo';
    case Estorno = 'estorno';
    case SaldoInicial = 'saldo_inicial';

    public function getLabel(): string
    {
        return match ($this) {
            self::PagamentoLancamento => 'Pagamento de conta',
            self::RecebimentoLancamento => 'Recebimento',
            self::Adiantamento => 'Adiantamento / vale',
            self::Transferencia => 'Transferência',
            self::AjusteSaldo => 'Ajuste de saldo',
            self::Estorno => 'Estorno',
            self::SaldoInicial => 'Saldo inicial',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PagamentoLancamento => 'danger',
            self::RecebimentoLancamento => 'success',
            self::Adiantamento => 'warning',
            self::Transferencia => 'info',
            self::AjusteSaldo => 'gray',
            self::Estorno => 'gray',
            self::SaldoInicial => 'gray',
        };
    }
}
