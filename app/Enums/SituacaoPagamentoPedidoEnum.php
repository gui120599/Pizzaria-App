<?php

namespace App\Enums;

/**
 * Situação do pagamento de um pedido, para a lista, o filtro e a exportação.
 *
 * É derivada, não uma coluna: ver Pedido::situacaoPagamento(). O valor
 * recebido de verdade vem da Venda; o pagamento "combinado" no atendimento é
 * só informativo e não entra aqui.
 */
enum SituacaoPagamentoPedidoEnum: string
{
    case PAGO = 'pago';
    case PARCIAL = 'parcial';
    case A_RECEBER = 'a_receber';

    public function label(): string
    {
        return match ($this) {
            self::PAGO => 'Pago',
            self::PARCIAL => 'Parcial',
            self::A_RECEBER => 'A receber',
        };
    }

    /** @return array<string, string> value => label */
    public static function paraSelect(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $situacao): array => [$situacao->value => $situacao->label()])
            ->all();
    }
}
