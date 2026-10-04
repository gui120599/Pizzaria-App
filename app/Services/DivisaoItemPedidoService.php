<?php

namespace App\Services;

use App\Exceptions\ItemNaoDivisivelException;
use App\Models\AdicionaisItemPedido;
use App\Models\ItensPedido;
use App\Models\PromocaoAdicionalConsumo;
use App\Models\PromocaoConsumo;
use Illuminate\Support\Facades\DB;

/**
 * Separa parte da quantidade de um item de pedido numa linha nova, para o
 * caixa cobrar só algumas unidades numa venda (ex.: 1 de 3 cervejas). Os
 * valores (desconto, adicionais, total) são repartidos em centavos na
 * proporção da quantidade: as duas linhas somam exatamente a original.
 */
class DivisaoItemPedidoService
{
    /**
     * @param  float  $quantidade  Unidades que vão para a linha nova
     * @return ItensPedido A linha nova, ainda sem venda
     *
     * @throws ItemNaoDivisivelException
     */
    public function dividir(ItensPedido $item, float $quantidade): ItensPedido
    {
        return DB::transaction(function () use ($item, $quantidade): ItensPedido {
            $item = ItensPedido::whereKey($item->id)->lockForUpdate()->firstOrFail();

            $this->validar($item, $quantidade);

            $total = (float) $item->item_pedido_quantidade;
            $proporcao = $quantidade / $total;

            $novo = $item->replicate();
            $novo->item_pedido_quantidade = $quantidade;
            $novo->item_pedido_venda_id = null;
            $novo->item_pedido_item_venda_id = null;

            foreach (['item_pedido_desconto', 'item_pedido_valor_adicionais', 'item_pedido_valor'] as $campo) {
                $parte = round((float) $item->{$campo} * $proporcao, 2);
                $novo->{$campo} = $parte;
                $item->{$campo} = round((float) $item->{$campo} - $parte, 2);
            }

            $item->item_pedido_quantidade = $total - $quantidade;
            $item->save();
            $novo->save();

            $item->adicionaisItemPedido()->get()->each(function (AdicionaisItemPedido $adicional) use ($novo, $proporcao): void {
                $copia = $adicional->replicate();
                $copia->aip_item_pedido_id = $novo->id;
                $copia->aip_quantidade = round((float) $adicional->aip_quantidade * $proporcao, 3);
                $copia->aip_valor_total = round((float) $adicional->aip_valor_total * $proporcao, 2);
                $copia->save();

                $adicional->update([
                    'aip_quantidade' => round((float) $adicional->aip_quantidade - $copia->aip_quantidade, 3),
                    'aip_valor_total' => round((float) $adicional->aip_valor_total - $copia->aip_valor_total, 2),
                ]);
            });

            return $novo;
        });
    }

    /**
     * Checagem barata (só colunas da linha) para a tela decidir se oferece
     * "Dividir"; os consumos de promoção são conferidos em dividir().
     */
    public static function podeOferecer(ItensPedido $item): bool
    {
        return $item->item_pedido_status === 'INSERIDO'
            && $item->item_pedido_venda_id === null
            && (float) $item->item_pedido_quantidade > 1
            && $item->item_pedido_promocao_id === null
            && $item->item_pedido_promocao_adicional_regra_id === null
            && $item->item_pedido_promocao_adicional_oferta_id === null
            && $item->item_pedido_origem_id === null;
    }

    /**
     * @throws ItemNaoDivisivelException
     */
    private function validar(ItensPedido $item, float $quantidade): void
    {
        if ($item->item_pedido_status !== 'INSERIDO' || $item->item_pedido_venda_id !== null) {
            throw new ItemNaoDivisivelException('Só dá para dividir um item que ainda não está em nenhuma venda.');
        }

        if ($this->ehPromocional($item)) {
            throw new ItemNaoDivisivelException('Item de promoção não pode ser dividido.');
        }

        $total = (float) $item->item_pedido_quantidade;

        if ($quantidade < 1 || floor($quantidade) !== $quantidade || $quantidade >= $total) {
            throw new ItemNaoDivisivelException('Informe uma quantidade inteira entre 1 e '.$this->formatar(ceil($total) - 1).'.');
        }
    }

    /**
     * Itens de promoção ficam inteiros: os consumos (teto da promoção, gatilho
     * e oferta) estão amarrados ao id da linha.
     */
    private function ehPromocional(ItensPedido $item): bool
    {
        return $item->item_pedido_promocao_id !== null
            || $item->item_pedido_promocao_adicional_regra_id !== null
            || $item->item_pedido_promocao_adicional_oferta_id !== null
            || $item->item_pedido_origem_id !== null
            || $item->itensOferta()->exists()
            || PromocaoConsumo::where('consumo_item_pedido_id', $item->id)->exists()
            || PromocaoAdicionalConsumo::where('pac_item_pedido_gatilho_id', $item->id)->orWhere('pac_item_pedido_oferta_id', $item->id)->exists();
    }

    private function formatar(float $quantidade): string
    {
        return rtrim(rtrim(number_format($quantidade, 3, ',', ''), '0'), ',');
    }
}
