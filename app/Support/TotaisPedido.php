<?php

namespace App\Support;

use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use Illuminate\Support\Collection;

/**
 * Regra única de totais de um pedido a partir das suas linhas de item.
 *
 * `item_pedido_valor` já é LÍQUIDO (o desconto do item foi subtraído na
 * gravação). Portanto o valor bruto — o "Subtotal" exibido antes do desconto —
 * é `soma(item_pedido_valor) + soma(item_pedido_desconto)`. Tratar o líquido
 * como bruto conta o desconto duas vezes (ver histórico de ConfirmacoesPedidos).
 *
 * O frete só é cobrado quando o valor líquido (após TODOS os descontos) fica
 * abaixo do mínimo da opção de entrega — mesma regra do checkout do cardápio.
 */
class TotaisPedido
{
    /**
     * @param  Collection<int, ItensPedido>  $itens  linhas com status INSERIDO
     * @param  float  $descontoPedido  desconto manual aplicado ao pedido inteiro (fora dos itens)
     * @return array{itens: float, desconto: float, frete: float, total: float}
     */
    public static function paraItens(Collection $itens, ?OpcoesEntregas $opcao, float $descontoPedido = 0.0): array
    {
        $liquidoItens = round((float) $itens->sum('item_pedido_valor'), 2);
        $descontoItens = round((float) $itens->sum('item_pedido_desconto'), 2);
        $descontoPedido = round(max(0.0, $descontoPedido), 2);

        $liquido = round(max(0.0, $liquidoItens - $descontoPedido), 2);
        $frete = self::frete($opcao, $liquido);

        return [
            'itens' => round($liquidoItens + $descontoItens, 2),
            'desconto' => round($descontoItens + $descontoPedido, 2),
            'frete' => $frete,
            'total' => round(max(0.0, $liquido + $frete), 2),
        ];
    }

    public static function frete(?OpcoesEntregas $opcao, float $liquido): float
    {
        if (! $opcao || $opcao->opcaoentrega_valor_frete <= 0) {
            return 0.0;
        }

        if ($opcao->opcaoentrega_min_valor_frete > 0 && $liquido >= $opcao->opcaoentrega_min_valor_frete) {
            return 0.0;
        }

        return round((float) $opcao->opcaoentrega_valor_frete, 2);
    }
}
