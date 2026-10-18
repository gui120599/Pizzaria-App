<?php

namespace App\Services;

use App\Enums\StatusPedidoEnum;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Support\TotaisPedido;

/**
 * Mantém pedido_valor_* igual à soma das linhas INSERIDO do pedido. Chamado
 * dentro da transação que altera os itens (ver LancamentoItemPedidoService),
 * para o total nunca depender de um save() posterior da tela.
 *
 * Pedido INICIADO (rascunho: checkout, rodada do garçom) fica de fora: ele
 * recebe o total no envio/confirmação. Não reprecifica nada, os preços de
 * cada linha já estão congelados no item.
 */
class TotaisPedidoService
{
    /**
     * Trava a linha do pedido. Chamar no início da transação, antes de gravar
     * o item, para manter a ordem pedido → itens (mesma do PedidoStatusService).
     */
    public function travar(int $pedidoId): Pedido
    {
        return Pedido::whereKey($pedidoId)->lockForUpdate()->firstOrFail();
    }

    /**
     * @return array{itens: float, desconto: float, frete: float, total: float}
     */
    public function recalcular(Pedido|int $pedido): array
    {
        $pedido = $this->travar($pedido instanceof Pedido ? $pedido->getKey() : $pedido);

        if ($pedido->pedido_status === StatusPedidoEnum::INICIADO->value) {
            return [
                'itens' => (float) $pedido->pedido_valor_itens,
                'desconto' => (float) $pedido->pedido_valor_desconto,
                'frete' => (float) $pedido->pedido_valor_frete,
                'total' => (float) $pedido->pedido_valor_total,
            ];
        }

        $itens = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
            ->where('item_pedido_status', 'INSERIDO')
            ->get();

        // Desconto do cupom só existe onde a coluna existe (feature de cupom).
        $descontoPedido = (float) ($pedido->getAttribute('pedido_valor_desconto_cupom') ?? 0);
        $opcao = $pedido->pedido_opcaoentrega_id ? $pedido->opcaoEntrega : null;

        $totais = TotaisPedido::paraItens($itens, $opcao, $descontoPedido);

        $novos = [
            'pedido_valor_itens' => $totais['itens'],
            'pedido_valor_desconto' => $totais['desconto'],
            'pedido_valor_frete' => $totais['frete'],
            'pedido_valor_total' => $totais['total'],
        ];

        $mudou = collect($novos)->contains(
            fn (float $valor, string $coluna): bool => abs((float) $pedido->{$coluna} - $valor) > 0.001
        );

        if ($mudou) {
            $pedido->update($novos);
        }

        $this->acompanharPagamentoCombinado($pedido, $totais['total']);

        return $totais;
    }

    /**
     * Com exatamente uma forma combinada e ainda não paga, o valor dela
     * acompanha o novo total. Com várias linhas, ou alguma paga, nada muda
     * (a tela avisa a divergência).
     */
    private function acompanharPagamentoCombinado(Pedido $pedido, float $total): void
    {
        $pagamentos = $pedido->pagamentosCombinados()->get();

        if ($pagamentos->count() !== 1 || $pagamentos->first()->pg_pedido_pago) {
            return;
        }

        $pagamento = $pagamentos->first();

        if (abs((float) $pagamento->pg_pedido_valor - $total) > 0.001) {
            $pagamento->update(['pg_pedido_valor' => $total]);
        }
    }
}
