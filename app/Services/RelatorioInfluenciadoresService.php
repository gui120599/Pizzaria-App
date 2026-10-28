<?php

namespace App\Services;

use App\Enums\TipoMovimentoCarteiraEnum;
use App\Models\CarteiraMovimento;
use App\Models\CliqueReferencia;
use App\Models\Influenciador;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\ResgateCarteira;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Resultado do programa por influenciador no período: cliques no link,
 * pedidos pagos, valor líquido vendido, descontos de cupom concedidos,
 * comissões creditadas, resgates (Pix e consumo em pizza) e saldo a pagar.
 */
class RelatorioInfluenciadoresService
{
    public function __construct(private readonly Carbon $inicio, private readonly Carbon $fim) {}

    /**
     * @return Collection<int, array{id: int, influenciador: string, cliques: int, pedidos: int, vendas: float, descontos: float, comissoes: float, resgates_pix: float, consumo: float, saldo: float}>
     */
    public function linhas(): Collection
    {
        $cliques = CliqueReferencia::query()
            ->whereBetween('created_at', [$this->inicio, $this->fim])
            ->selectRaw('clique_influenciador_id as id, COUNT(*) as total')
            ->groupBy('clique_influenciador_id')
            ->pluck('total', 'id');

        $movimentos = CarteiraMovimento::query()
            ->whereBetween('created_at', [$this->inicio, $this->fim])
            ->selectRaw('cm_influenciador_id as id, cm_tipo, SUM(cm_valor) as total')
            ->groupBy('cm_influenciador_id', 'cm_tipo')
            ->get()
            ->groupBy('id');

        $resgates = ResgateCarteira::query()
            ->whereBetween('rc_pago_em', [$this->inicio, $this->fim])
            ->selectRaw('rc_influenciador_id as id, SUM(rc_valor) as total')
            ->groupBy('rc_influenciador_id')
            ->pluck('total', 'id');

        $saldos = CarteiraMovimento::query()
            ->selectRaw('cm_influenciador_id as id, SUM(cm_valor) as total')
            ->groupBy('cm_influenciador_id')
            ->pluck('total', 'id');

        return Influenciador::withTrashed()
            ->orderBy('influenciador_nome')
            ->get()
            ->map(function (Influenciador $influenciador) use ($cliques, $movimentos, $resgates, $saldos): array {
                $pedidos = $this->pedidosPagos($influenciador->id);
                $doInflu = $movimentos->get($influenciador->id, collect());
                $valorDoTipo = fn (TipoMovimentoCarteiraEnum $tipo): float => (float) $doInflu->firstWhere('cm_tipo', $tipo)?->total;

                return [
                    'id' => $influenciador->id,
                    'influenciador' => $influenciador->influenciador_nome,
                    'cliques' => (int) ($cliques[$influenciador->id] ?? 0),
                    'pedidos' => $pedidos->count(),
                    'vendas' => $this->somaItens($pedidos, 'item_pedido_valor'),
                    'descontos' => $this->somaItens($pedidos, 'item_pedido_desconto_cupom'),
                    // Crédito menos estorno: o que de fato ficou creditado no período.
                    'comissoes' => round($valorDoTipo(TipoMovimentoCarteiraEnum::CREDITO_COMISSAO) + $valorDoTipo(TipoMovimentoCarteiraEnum::ESTORNO_COMISSAO), 2),
                    'resgates_pix' => round((float) ($resgates[$influenciador->id] ?? 0), 2),
                    // Consumo em pizza líquido de estornos (valor positivo).
                    'consumo' => round(-($valorDoTipo(TipoMovimentoCarteiraEnum::CONSUMO) + $valorDoTipo(TipoMovimentoCarteiraEnum::ESTORNO_CONSUMO)), 2),
                    'saldo' => round((float) ($saldos[$influenciador->id] ?? 0), 2),
                ];
            })
            ->filter(fn (array $linha): bool => $linha['cliques'] > 0 || $linha['pedidos'] > 0 || $linha['comissoes'] != 0.0
                || $linha['resgates_pix'] > 0 || $linha['consumo'] != 0.0 || abs($linha['saldo']) >= 0.01)
            ->values();
    }

    /** @return Collection<int, int> ids dos pedidos pagos (com venda) do influenciador no período */
    private function pedidosPagos(int $influenciadorId): Collection
    {
        return Pedido::query()
            ->whereHas('cupom', fn ($q) => $q->where('cupom_influenciador_id', $influenciadorId))
            ->whereNotNull('pedido_venda_id')
            ->where('pedido_status', '!=', 'CANCELADO')
            ->whereBetween('created_at', [$this->inicio, $this->fim])
            ->pluck('id');
    }

    /** @param  Collection<int, int>  $pedidoIds */
    private function somaItens(Collection $pedidoIds, string $coluna): float
    {
        if ($pedidoIds->isEmpty()) {
            return 0.0;
        }

        return round((float) ItensPedido::query()
            ->whereIn('item_pedido_pedido_id', $pedidoIds)
            ->where('item_pedido_status', 'INSERIDO')
            ->sum($coluna), 2);
    }
}
