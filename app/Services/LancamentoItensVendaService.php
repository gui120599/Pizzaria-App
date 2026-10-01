<?php

namespace App\Services;

use App\Models\AdicionaisItemPedido;
use App\Models\AdicionaisItemVenda;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Models\Venda;
use Illuminate\Support\Collection;

/**
 * Copia itens de um Pedido (avulso ou de sessão de mesa) para uma Venda,
 * mesclando na linha existente quando possível — extraído sem alteração de
 * comportamento de App\Filament\Pages\OperarVenda::adicionarItensPedidoNaVenda,
 * para ser reaproveitado também pelo webhook Stone (StoneVendaAutomaticaService),
 * que não pode instanciar uma Livewire Page. Espelha
 * ItensVendaController::adicionarItensPedidoNaVenda (legado).
 */
class LancamentoItensVendaService
{
    /**
     * Lança todos os pedidos ativos (não cancelados/finalizados) de uma
     * sessão de mesa na venda, um a um. Retorna a quantidade total de
     * ItensPedido lançados.
     */
    public function lancarSessaoMesa(Venda $venda, SessaoMesa $sessaoMesa): int
    {
        $pedidos = Pedido::where('pedido_sessao_mesa_id', $sessaoMesa->id)
            ->whereNotIn('pedido_status', ['CANCELADO', 'FINALIZADO'])
            ->get();

        $total = 0;
        foreach ($pedidos as $pedido) {
            $total += $this->lancarPedido($venda, $pedido);
        }

        return $total;
    }

    /**
     * Lança na venda os itens ainda não lançados (INSERIDO, sem
     * item_pedido_venda_id) de um Pedido inteiro, e marca item_pedido_venda_id
     * — o mesmo filtro usado pelo PDV, que garante idempotência (reclicar
     * "lançar" num pedido já lançado não soma de novo). Retorna a quantidade
     * de ItensPedido lançados.
     */
    public function lancarPedido(Venda $venda, Pedido $pedido): int
    {
        $itensPedido = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
            ->where('item_pedido_status', 'INSERIDO')
            ->whereNull('item_pedido_venda_id')
            ->with('adicionaisItemPedido', 'produto')
            ->get();

        if ($itensPedido->isEmpty()) {
            return 0;
        }

        $this->lancarItens($venda, $itensPedido);

        ItensPedido::whereIn('id', $itensPedido->pluck('id'))
            ->update(['item_pedido_venda_id' => $venda->id]);

        return $itensPedido->count();
    }

    /**
     * Núcleo da cópia: mescla na linha de ItensVenda existente (mesmo
     * produto, sem adicionais, ainda nesta venda) quando o item atual também
     * não tem adicionais, senão cria uma linha nova com tributos calculados
     * pelos percentuais do produto. Não marca item_pedido_venda_id — quem
     * chama decide o momento (permite filtro adicional, ex.: por cliente).
     *
     * @param  Collection<int, ItensPedido>  $itensPedido
     */
    public function lancarItens(Venda $venda, Collection $itensPedido): void
    {
        foreach ($itensPedido as $item) {
            $itemAtualTemAdicionais = ($item->adicionaisItemPedido !== null && ! $item->adicionaisItemPedido->isEmpty())
                || ($item->item_pedido_valor_adicionais > 0);

            // Pizza de sabores nunca mescla: é uma linha própria com os sabores
            // congelados — somar ½ calabresa numa calabresa inteira (como era
            // antes da linha única) perdia o agrupamento da pizza na venda/NF-e.
            $itemVenda = $item->ehMultiSabor()
                ? null
                : ItensVenda::where('item_venda_produto_id', $item->item_pedido_produto_id)
                    ->where('item_venda_venda_id', $venda->id)
                    ->where('item_venda_valor_adicionais', 0)
                    ->whereNull('item_venda_sabores')
                    ->first();

            if ($itemVenda && ! $itemAtualTemAdicionais) {
                $itemVenda->item_venda_quantidade += $item->item_pedido_quantidade;
                $itemVenda->item_venda_desconto += $item->item_pedido_desconto;
                $itemVenda->item_venda_valor += $item->item_pedido_valor;
                $itemVenda->item_venda_quantidade_tributavel += $item->item_pedido_quantidade;
                $this->somarTributosItemVenda($itemVenda, $item->produto, $item->item_pedido_valor);
                $itemVenda->save();
            } else {
                $nextItemNumber = (ItensVenda::where('item_venda_venda_id', $venda->id)->max('item_numero') ?? 0) + 1;

                $valorEfetivo = $item->item_pedido_valor;
                $itemVenda = ItensVenda::create([
                    'item_numero' => $nextItemNumber,
                    'item_venda_venda_id' => $venda->id,
                    'item_venda_produto_id' => $item->item_pedido_produto_id,
                    'item_venda_quantidade' => $item->item_pedido_quantidade,
                    'item_venda_valor_unitario' => $item->item_pedido_valor_unitario,
                    'item_venda_valor_adicionais' => $item->item_pedido_valor_adicionais,
                    'item_venda_desconto' => $item->item_pedido_desconto,
                    'item_venda_valor' => $valorEfetivo,
                    'item_venda_sabores' => $item->item_pedido_sabores,
                    'item_venda_status' => 'INSERIDO',
                    'item_venda_quantidade_tributavel' => $item->item_pedido_quantidade,
                    'item_venda_valor_unitario_tributavel' => $item->item_pedido_valor_unitario,
                    'item_venda_valor_base_calculo' => $valorEfetivo,
                    'item_venda_valor_icms' => ($valorEfetivo * $item->produto->produto_valor_percentual_icms) / 100,
                    'item_venda_valor_pis' => ($valorEfetivo * $item->produto->produto_valor_percentual_pis) / 100,
                    'item_venda_valor_cofins' => ($valorEfetivo * $item->produto->produto_valor_percentual_cofins) / 100,
                    'item_venda_valor_total_tributos' => ($valorEfetivo * ($item->produto->produto_valor_percentual_icms + $item->produto->produto_valor_percentual_pis + $item->produto->produto_valor_percentual_cofins)) / 100,
                ]);
            }

            $this->adicionarOuAtualizarAdicionaisDoItem($item, $itemVenda);
        }
    }

    private function somarTributosItemVenda(ItensVenda $itemVenda, $produto, float $valorTotal): void
    {
        $itemVenda->item_venda_valor_base_calculo += $valorTotal;
        $itemVenda->item_venda_valor_icms += ($valorTotal * $produto->produto_valor_percentual_icms) / 100;
        $itemVenda->item_venda_valor_pis += ($valorTotal * $produto->produto_valor_percentual_pis) / 100;
        $itemVenda->item_venda_valor_cofins += ($valorTotal * $produto->produto_valor_percentual_cofins) / 100;
        $itemVenda->item_venda_valor_total_tributos += ($valorTotal * ($produto->produto_valor_percentual_icms + $produto->produto_valor_percentual_pis + $produto->produto_valor_percentual_cofins)) / 100;
    }

    private function adicionarOuAtualizarAdicionaisDoItem(ItensPedido $item, ItensVenda $itemVenda): void
    {
        $adicionaisPedido = AdicionaisItemPedido::where('aip_item_pedido_id', $item->id)->get();

        foreach ($adicionaisPedido as $adicionalPedido) {
            $adicionalVenda = AdicionaisItemVenda::where('aiv_item_venda_id', $itemVenda->id)
                ->where('aiv_adicional_id', $adicionalPedido->aip_adicional_id)
                ->first();

            if ($adicionalVenda) {
                $adicionalVenda->aiv_quantidade += $adicionalPedido->aip_quantidade;
                $adicionalVenda->aiv_valor_total += $adicionalPedido->aip_valor_total;
                $adicionalVenda->save();
            } else {
                AdicionaisItemVenda::create([
                    'aiv_adicional_id' => $adicionalPedido->aip_adicional_id,
                    'aiv_item_venda_id' => $itemVenda->id,
                    'aiv_valor_unitario' => $adicionalPedido->aip_valor_unitario,
                    'aiv_quantidade' => $adicionalPedido->aip_quantidade,
                    'aiv_valor_total' => $adicionalPedido->aip_valor_total,
                ]);
            }
        }
    }
}
