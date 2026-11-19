<?php

namespace App\Livewire\Concerns;

use App\Enums\ProdutoTipoEnum;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\PedidoComboAplicacao;
use App\Models\Produto;
use App\Services\PrecificadorService;
use App\Services\PromocaoCombo\AplicacaoCombo;
use App\Services\PromocaoCombo\PlanoCombo;
use App\Services\PromocaoCombo\PromocaoComboService;

/**
 * Promoção Combo no PedidoProdutoSelector (garçom e Atender Pedido).
 *
 * Com pedido gravado, o desconto já está nas linhas (TotaisPedidoService):
 * a lista é relida para mostrar o valor novo de todas. Na prévia do Atender
 * Pedido (sem pedido), o mesmo motor roda sobre os itens da lista e o save
 * reaplica no banco. Depois de cada mudança, combo novo comemora e item
 * participante sem par convida a completar — no máximo uma vez até fechar
 * outro combo da mesma promoção.
 *
 * Quem usa precisa de $pedidoId, $itens, carregarItensDB() e selecionarProduto().
 */
trait ConvidaPromocaoCombo
{
    /**
     * Modal da Promoção Combo: 'conquista' (combo fechado) ou 'convite' (falta item). Null = fechado.
     *
     * @var array<string, mixed>|null
     */
    public ?array $comboModal = null;

    /**
     * Combos e unidades participantes por promoção na última conferência.
     * Null = ainda não conferido (abrir a tela não comemora nem convida).
     *
     * @var array{combos: array<int, int>, unidades: array<int, int>}|null
     */
    public ?array $comboEstado = null;

    /** @var array<int, int> promoção => combos fechados quando o convite foi recusado */
    public array $combosRecusados = [];

    /** Atualiza valores (combo nas linhas) e decide comemoração/convite. */
    protected function sincronizarPromocaoCombo(bool $convidar = true): void
    {
        $plano = $this->pedidoId ? $this->planoComboDoPedido() : $this->aplicarComboNaPrevia();

        if ($plano === null) {
            return;
        }

        $combos = [];
        $unidades = [];

        foreach ($plano->aplicacoes as $aplicacao) {
            $combos[$aplicacao->promocaoId] = ($combos[$aplicacao->promocaoId] ?? 0) + 1;
            $unidades[$aplicacao->promocaoId] = ($unidades[$aplicacao->promocaoId] ?? 0) + $aplicacao->quantidade;
        }

        foreach ($plano->pendencias as $pendencia) {
            $unidades[$pendencia->promocaoId] = ($unidades[$pendencia->promocaoId] ?? 0) + $pendencia->quantidade - $pendencia->faltam;
        }

        $antes = $this->comboEstado;
        $this->comboEstado = ['combos' => $combos, 'unidades' => $unidades];

        if (! $convidar || $antes === null) {
            return;
        }

        $nova = collect($plano->aplicacoes)
            ->filter(fn (AplicacaoCombo $a): bool => ($combos[$a->promocaoId] ?? 0) > ($antes['combos'][$a->promocaoId] ?? 0))
            ->last();

        if ($nova) {
            $this->comboModal = $this->dadosDaConquista($nova);

            return;
        }

        foreach ($plano->pendencias as $pendencia) {
            $fechados = $combos[$pendencia->promocaoId] ?? 0;

            if (! $pendencia->sugerir
                || ($unidades[$pendencia->promocaoId] ?? 0) <= ($antes['unidades'][$pendencia->promocaoId] ?? 0)
                || (isset($this->combosRecusados[$pendencia->promocaoId]) && $fechados <= $this->combosRecusados[$pendencia->promocaoId])) {
                continue;
            }

            $produtos = $this->produtosDoCombo($pendencia->promocaoId);

            if ($produtos !== []) {
                $this->comboModal = [
                    'tipo' => 'convite',
                    'promocao_id' => $pendencia->promocaoId,
                    'nome' => $pendencia->nome,
                    'quantidade' => $pendencia->quantidade,
                    'preco' => $pendencia->precoCentavos / 100,
                    'faltam' => $pendencia->faltam,
                    'combos_fechados' => $fechados,
                    'produtos' => $produtos,
                ];

                return;
            }
        }
    }

    /** Escolheu uma pizza no convite: segue o fluxo normal do produto. */
    public function escolherDoCombo(int $produtoId): void
    {
        $permitidos = collect($this->comboModal['produtos'] ?? [])->pluck('id')->map(fn ($id): int => (int) $id);
        $this->comboModal = null;

        if ($permitidos->contains($produtoId)) {
            $this->selecionarProduto($produtoId);
        }
    }

    /** Fechou o convite sem escolher: só volta depois de fechar outro combo da promoção. */
    public function recusarCombo(): void
    {
        if (($this->comboModal['tipo'] ?? null) === 'convite') {
            $this->combosRecusados[(int) $this->comboModal['promocao_id']] = (int) $this->comboModal['combos_fechados'];
        }

        $this->comboModal = null;
    }

    /** Pedido gravado: relê a lista (valores com o combo) e devolve o plano atual. */
    private function planoComboDoPedido(): ?PlanoCombo
    {
        $pedido = Pedido::find($this->pedidoId);

        if (! $pedido) {
            return null;
        }

        $plano = app(PromocaoComboService::class)->planoDoPedido($pedido);
        $tinhaCombo = $this->comboEstado !== null && array_sum($this->comboEstado['combos']) > 0;

        if ($plano->aplicacoes !== [] || $tinhaCombo || PedidoComboAplicacao::where('pca_pedido_id', $pedido->id)->exists()) {
            $this->carregarItensDB();
        }

        return $plano;
    }

    /**
     * Prévia (pedido novo do Atender Pedido): o motor roda sobre a lista e
     * cada item guarda a sua parte em 'desconto_combo' ('valor' já líquido,
     * 'desconto' com a parte somada, como uma linha gravada).
     */
    private function aplicarComboNaPrevia(): PlanoCombo
    {
        $comBrinde = collect($this->itens)->pluck('origem_tmp')->filter()->map(fn ($id): string => (string) $id)->all();
        $linhas = [];

        foreach ($this->itens as $item) {
            if (! empty($item['oferta_id'])) {
                continue;
            }

            $linha = new ItensPedido([
                'item_pedido_produto_id' => $item['produto_id'],
                'item_pedido_quantidade' => $item['quantidade'],
                'item_pedido_valor_unitario' => $item['valor_unitario'],
                'item_pedido_desconto' => round((float) ($item['desconto'] ?? 0) - (float) ($item['desconto_combo'] ?? 0), 2),
                'item_pedido_valor_adicionais' => $item['adicionais_valor'] ?? 0,
                'item_pedido_sabores' => $item['sabores'] ?? null,
                'item_pedido_promocao_id' => $item['promocao_id'] ?? null,
                'item_pedido_promocao_adicional_regra_id' => $item['promocao_adicional_regra_id'] ?? null,
                'item_pedido_cortesia' => (bool) ($item['cortesia'] ?? false),
            ]);
            $linha->setAttribute('itens_oferta_count', in_array((string) $item['id'], $comBrinde, true) ? 1 : 0);
            $linhas[(string) $item['id']] = $linha;
        }

        $plano = app(PromocaoComboService::class)->planejar($linhas);
        $porLinha = $plano->descontoPorLinha();

        foreach ($this->itens as &$item) {
            if (! empty($item['oferta_id'])) {
                continue;
            }

            $anterior = (float) ($item['desconto_combo'] ?? 0);
            $novo = ($porLinha[(string) $item['id']] ?? 0) / 100;

            if (abs($anterior - $novo) < 0.001) {
                continue;
            }

            $item['valor'] = round((float) $item['valor'] + $anterior - $novo, 2);
            $item['desconto'] = round((float) ($item['desconto'] ?? 0) - $anterior + $novo, 2);
            $item['desconto_combo'] = $novo;
        }
        unset($item);

        return $plano;
    }

    /** @return array<string, mixed> */
    private function dadosDaConquista(AplicacaoCombo $aplicacao): array
    {
        $nomes = collect($this->itens)->keyBy(fn (array $i): string => (string) $i['id']);

        return [
            'tipo' => 'conquista',
            'nome' => $aplicacao->nome,
            'preco' => $aplicacao->precoCentavos / 100,
            'valor_cheio' => $aplicacao->valorCheioCentavos() / 100,
            'desconto' => $aplicacao->descontoCentavos() / 100,
            'itens' => collect($aplicacao->partes)
                ->map(fn (array $parte, string $chave): string => ($parte['unidades'] > 1 ? $parte['unidades'].'x ' : '').($nomes[$chave]['produto_nome'] ?? 'Item'))
                ->values()
                ->all(),
        ];
    }

    /**
     * Produtos do cardápio do garçom que fecham o combo, com o preço atual.
     *
     * @return list<array{id: int, nome: string, foto: ?string, preco: float}>
     */
    private function produtosDoCombo(int $promocaoId): array
    {
        $produtos = Produto::with('categoria')
            ->whereIn('produto_tipo', [ProdutoTipoEnum::PRODUZIDO->value, ProdutoTipoEnum::REVENDA->value])
            ->where('produto_cardapio_garcom', true)
            ->whereHas('categoria', fn ($q) => $q->where('categoria_cardapio_garcom', true))
            ->orderBy('produto_ordem')
            ->orderBy('produto_descricao')
            ->get()
            ->keyBy('id');

        $promocao = collect(app(PromocaoComboService::class)->paraCardapio(
            $produtos->map(fn (Produto $p): array => ['categoria_id' => $p->produto_categoria_id, 'relampago' => false])->all(),
            $this->pedidoId !== null && Pedido::whereKey($this->pedidoId)->whereNotNull('pedido_sessao_mesa_id')->exists(),
        ))->firstWhere('promocaoId', $promocaoId);

        $precificador = app(PrecificadorService::class);

        return collect($promocao['produtos'] ?? [])
            ->take(40)
            ->map(fn (int $id): array => [
                'id' => $id,
                'nome' => $produtos[$id]->produto_descricao,
                'foto' => $produtos[$id]->getMiniaturaUrl(56),
                'preco' => $precificador->resolver($produtos[$id], considerarPromoAdicional: false)->precoFinal(),
            ])
            ->values()
            ->all();
    }
}
