<?php

namespace App\Services;

use App\Enums\AcaoAutorizadaEnum;
use App\Models\AutorizacaoGerente;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Auditoria das cortesias do período: o que saiu sem cobrança, a preço de
 * tabela e ao custo médio atual do produto, com motivo e quem autorizou.
 * Duas origens: item de pedido (cortesia_em) e linha avulsa lançada no caixa.
 *
 * @phpstan-type LinhaCortesia array{data: CarbonInterface, origem: string, produto: string, quantidade: float, valor_tabela: float, custo: float, motivo: ?string, autorizador: ?string}
 */
class RelatorioCortesiasService
{
    public function __construct(
        private readonly CarbonInterface $inicio,
        private readonly CarbonInterface $fim,
        private readonly string $busca = '',
    ) {}

    /**
     * @return Collection<int, LinhaCortesia>
     */
    public function linhas(): Collection
    {
        $busca = mb_strtolower(trim($this->busca));

        return $this->deItensDePedido()
            ->merge($this->deLinhasAvulsas())
            ->when($busca !== '', fn (Collection $linhas) => $linhas->filter(
                fn (array $linha): bool => str_contains(mb_strtolower($linha['produto'].' '.$linha['motivo'].' '.$linha['autorizador'].' '.$linha['origem']), $busca)
            ))
            ->sortByDesc(fn (array $linha) => $linha['data']->timestamp)
            ->values();
    }

    /**
     * @return array{itens: int, valor_tabela: float, custo: float}
     */
    public function totais(): array
    {
        $linhas = $this->linhas();

        return [
            'itens' => $linhas->count(),
            'valor_tabela' => round($linhas->sum('valor_tabela'), 2),
            'custo' => round($linhas->sum('custo'), 2),
        ];
    }

    /**
     * @return Collection<int, LinhaCortesia>
     */
    private function deItensDePedido(): Collection
    {
        return ItensPedido::where('item_pedido_cortesia', true)
            ->where('item_pedido_status', 'INSERIDO')
            ->whereBetween('item_pedido_cortesia_em', [$this->inicio->copy()->startOfDay(), $this->fim->copy()->endOfDay()])
            ->with(['produto', 'usuarioCortesia'])
            ->get()
            ->map(fn (ItensPedido $item): array => [
                'data' => $item->item_pedido_cortesia_em,
                'origem' => 'Pedido #'.$item->item_pedido_pedido_id,
                'produto' => $item->nomeProduto(),
                'quantidade' => (float) $item->item_pedido_quantidade,
                'valor_tabela' => round((float) $item->item_pedido_desconto, 2),
                'custo' => round((float) $item->produto?->produto_custo_medio * (float) $item->item_pedido_quantidade, 2),
                'motivo' => $item->item_pedido_cortesia_motivo,
                'autorizador' => $item->usuarioCortesia?->name,
            ]);
    }

    /**
     * Linhas de cortesia criadas direto no caixa (sem item de pedido por trás).
     *
     * @return Collection<int, LinhaCortesia>
     */
    private function deLinhasAvulsas(): Collection
    {
        $linhas = ItensVenda::where('item_venda_cortesia', true)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('itens_pedidos')
                ->whereColumn('itens_pedidos.item_pedido_item_venda_id', 'itens_vendas.id'))
            ->whereBetween('updated_at', [$this->inicio->copy()->startOfDay(), $this->fim->copy()->endOfDay()])
            ->with('produto')
            ->get();

        $autorizacoes = AutorizacaoGerente::where('autorizacao_acao', AcaoAutorizadaEnum::CONCEDER_CORTESIA)
            ->where('autorizacao_auditavel_type', (new ItensVenda)->getMorphClass())
            ->whereIn('autorizacao_auditavel_id', $linhas->pluck('id'))
            ->with('autorizador')
            ->orderBy('id')
            ->get()
            ->groupBy('autorizacao_auditavel_id')
            ->map(fn (Collection $grupo) => $grupo->last());

        return $linhas->map(function (ItensVenda $linha) use ($autorizacoes): array {
            $autorizacao = $autorizacoes->get($linha->id);

            return [
                'data' => $linha->updated_at,
                'origem' => 'Venda #'.$linha->item_venda_venda_id,
                'produto' => $linha->produto?->produto_descricao ?? '—',
                'quantidade' => (float) $linha->item_venda_quantidade,
                'valor_tabela' => round((float) $linha->item_venda_valor_tabela, 2),
                'custo' => round((float) $linha->produto?->produto_custo_medio * (float) $linha->item_venda_quantidade, 2),
                'motivo' => $autorizacao?->autorizacao_motivo,
                'autorizador' => $autorizacao?->autorizador?->name,
            ];
        })->values();
    }
}
