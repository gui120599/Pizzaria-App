<?php

namespace App\Support;

use App\Enums\StatusPedidoEnum;
use App\Models\ItensPedido;
use App\Models\SessaoMesa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Totais da conta de uma mesa/comanda: rodadas enviadas e ainda não pagas,
 * mais a taxa de serviço da sessão. Fonte única para a pré-conta do garçom,
 * a cobrança Stone da mesa e o lançamento da taxa na venda.
 */
final class ContaMesa
{
    /** Status de pedido que não entram na conta: rascunho, cancelado e já pago. */
    public const STATUS_FORA_DA_CONTA = [
        StatusPedidoEnum::INICIADO->value,
        StatusPedidoEnum::CANCELADO->value,
        StatusPedidoEnum::FINALIZADO->value,
    ];

    /**
     * Mesmo escopo da pré-conta impressa (PDFController::sessaoMesaPDF): itens
     * ativos de rodadas enviadas que ainda não foram lançados numa venda.
     */
    public static function subtotal(int $sessaoMesaId): float
    {
        return round((float) ItensPedido::query()
            ->whereHas('pedido', fn ($q) => $q
                ->where('pedido_sessao_mesa_id', $sessaoMesaId)
                ->whereNotIn('pedido_status', self::STATUS_FORA_DA_CONTA))
            ->where('item_pedido_status', 'INSERIDO')
            ->whereNull('item_pedido_venda_id')
            ->sum('item_pedido_valor'), 2);
    }

    /**
     * Itens ainda na conta (mesmo escopo de subtotal()), com produto,
     * adicionais, pessoa e a rodada de origem — base da pré-conta.
     *
     * @return Collection<int, ItensPedido>
     */
    public static function itensEmAberto(int $sessaoMesaId, ?int $clienteId = null): Collection
    {
        return ItensPedido::query()
            ->whereHas('pedido', fn ($q) => $q
                ->where('pedido_sessao_mesa_id', $sessaoMesaId)
                ->whereNotIn('pedido_status', self::STATUS_FORA_DA_CONTA))
            ->where('item_pedido_status', 'INSERIDO')
            ->whereNull('item_pedido_venda_id')
            // 0 = itens da mesa sem pessoa ("Mesa geral").
            ->when($clienteId !== null, fn ($q) => $clienteId === 0
                ? $q->whereNull('item_pedido_cliente_id')
                : $q->where('item_pedido_cliente_id', $clienteId))
            ->with(['pedido.garcom', 'produto.categoria', 'adicionaisItemPedido.adicional', 'cliente'])
            ->orderBy('item_pedido_pedido_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * Conta separada por pessoa da mesa (chave 0 = mesa geral), com a taxa
     * de serviço proporcional a cada uma.
     *
     * @return array<int, array{nome: string, subtotal: float, taxa: float, total: float}>
     */
    public static function porPessoa(SessaoMesa $sessao): array
    {
        return self::itensEmAberto($sessao->id)
            ->groupBy(fn (ItensPedido $item) => (int) ($item->item_pedido_cliente_id ?? 0))
            ->map(function ($itens, int $clienteId) use ($sessao) {
                $subtotal = round((float) $itens->sum('item_pedido_valor'), 2);
                $taxa = self::taxaServico($sessao, $subtotal);

                return [
                    'nome' => $clienteId === 0 ? 'Mesa geral' : ($itens->first()->cliente?->cliente_nome ?? 'Cliente #'.$clienteId),
                    'subtotal' => $subtotal,
                    'taxa' => $taxa,
                    'total' => round($subtotal + $taxa, 2),
                ];
            })
            ->all();
    }

    public static function taxaServico(SessaoMesa $sessao, float $subtotal): float
    {
        return round($subtotal * (float) $sessao->sessao_mesa_taxa_servico_percentual / 100, 2);
    }

    /**
     * @return array{subtotal: float, percentual: float, taxa: float, total: float}
     */
    public static function para(SessaoMesa $sessao): array
    {
        $subtotal = self::subtotal($sessao->id);
        $taxa = self::taxaServico($sessao, $subtotal);

        return [
            'subtotal' => $subtotal,
            'percentual' => (float) $sessao->sessao_mesa_taxa_servico_percentual,
            'taxa' => $taxa,
            'total' => round($subtotal + $taxa, 2),
        ];
    }

    /**
     * Taxa de serviço devida pelos itens de mesa lançados numa venda: para
     * cada sessão de origem, o percentual da sessão sobre o valor dos itens
     * dela que estão nesta venda (arredondado por sessão, igual a para()).
     */
    public static function taxaServicoDaVenda(int $vendaId): float
    {
        return round(DB::table('itens_pedidos')
            ->join('pedidos', 'pedidos.id', '=', 'itens_pedidos.item_pedido_pedido_id')
            ->join('sessao_mesas', 'sessao_mesas.id', '=', 'pedidos.pedido_sessao_mesa_id')
            ->where('itens_pedidos.item_pedido_venda_id', $vendaId)
            ->where('itens_pedidos.item_pedido_status', 'INSERIDO')
            ->where('sessao_mesas.sessao_mesa_taxa_servico_percentual', '>', 0)
            ->groupBy('sessao_mesas.id', 'sessao_mesas.sessao_mesa_taxa_servico_percentual')
            ->selectRaw('SUM(itens_pedidos.item_pedido_valor) AS consumo, sessao_mesas.sessao_mesa_taxa_servico_percentual AS percentual')
            ->get()
            ->sum(fn ($linha) => round((float) $linha->consumo * (float) $linha->percentual / 100, 2)), 2);
    }
}
