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
 * O frete só é cobrado quando o valor líquido (após TODOS os descontos, inclusive
 * o do cupom) fica abaixo do mínimo da opção de entrega.
 */
class TotaisPedido
{
    /**
     * Cortesia (item_pedido_cortesia) não é desconto comercial: sai do subtotal
     * e do desconto e aparece na sua própria chave, para os relatórios.
     *
     * @param  Collection<int, ItensPedido>  $itens  linhas com status INSERIDO
     * @return array{itens: float, desconto: float, cortesia: float, frete: float, total: float}
     */
    public static function paraItens(Collection $itens, ?OpcoesEntregas $opcao): array
    {
        $liquido = round((float) $itens->sum('item_pedido_valor'), 2);
        $cortesia = round((float) $itens->where('item_pedido_cortesia', true)->sum('item_pedido_desconto'), 2);
        // O cupom já está rateado nos itens (item_pedido_desconto_cupom) e o valor
        // da linha já o descontou: entra no desconto, não é subtraído de novo.
        $descontoCupom = round((float) $itens->sum('item_pedido_desconto_cupom'), 2);
        $desconto = round((float) $itens->sum('item_pedido_desconto') - $cortesia + $descontoCupom, 2);
        $frete = self::frete($opcao, $liquido);

        return [
            'itens' => round($liquido + $desconto, 2),
            'desconto' => $desconto,
            'cortesia' => $cortesia,
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
