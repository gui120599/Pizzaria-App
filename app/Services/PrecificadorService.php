<?php

namespace App\Services;

use App\Models\Produto;
use App\Models\PromocaoAdicionalOferta;
use App\Models\PromocaoAdicionalRegra;
use App\Models\PromocaoRelampago;
use App\Models\PromocaoRelampagoProduto;
use App\Support\RateioCentavos;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Fonte única do preço de venda de um produto. O preço NUNCA vem do cliente:
 * quem decide é o servidor, consultando, nesta ordem de precedência:
 *
 *   1. promoção adicional com preço de gatilho configurado (par_preco_gatilho_override),
 *      vigente e com saldo — é a decisão mais específica e deliberada do admin;
 *   2. promoção relâmpago vigente e com saldo (preço no pivô);
 *   3. produto_preco_promocional, respeitando a janela de datas do produto;
 *   4. produto_preco_venda.
 *
 * Quando a regra de promoção adicional não define par_preco_gatilho_override
 * (a maioria das campanhas — ela só soma a linha da oferta, sem mexer no
 * preço do gatilho), o passo 1 não se aplica e a cadeia segue como antes.
 *
 * As promoções vigentes são memoizadas por instância — o serviço é resolvido
 * por requisição, então o carrinho inteiro enxerga o mesmo conjunto. O saldo
 * lido aqui é indicativo; quem garante o limite sob concorrência é o UPDATE
 * condicional em PromocaoRelampagoService/PromocaoAdicionalService.
 */
class PrecificadorService
{
    /** @var Collection<int, PromocaoRelampago>|null */
    private ?Collection $vigentes = null;

    /** @var Collection<int, PromocaoAdicionalRegra>|null */
    private ?Collection $regrasAdicionais = null;

    /** @return Collection<int, PromocaoRelampago> */
    public function promocoesVigentes(): Collection
    {
        return $this->vigentes ??= PromocaoRelampago::query()
            ->vigente()
            ->comSaldo()
            ->with(['promocaoProdutos'])
            ->orderBy('promocao_ordem')
            ->get();
    }

    /**
     * @return Collection<int, PromocaoAdicionalRegra>
     *
     * A checagem de vigência (que pode envolver recorrência) não entra no
     * whereHas — vigente() faz sentido só como método de instância; usá-lo
     * como scope dentro de um whereHas quebraria a subquery correlacionada.
     * Por isso filtra grosso no banco (campanha ativa) e refina em PHP, igual
     * a PromocaoRelampago::scopeVigente().
     */
    public function regrasAdicionaisVigentes(): Collection
    {
        return $this->regrasAdicionais ??= PromocaoAdicionalRegra::query()
            ->whereHas('promocao', fn ($q) => $q->where('promoad_ativa', true))
            ->with(['promocao.opcoesPagamento', 'ofertas'])
            ->get()
            ->filter(fn (PromocaoAdicionalRegra $regra) => $regra->promocao?->vigente() && $regra->ofertasDisponiveis()->isNotEmpty())
            ->values();
    }

    /**
     * Regra de promoção adicional vigente (com ao menos 1 oferta com saldo)
     * para um produto-gatilho, opcionalmente filtrando por forma de
     * pagamento. Quando $opcaoPagamentoId é null (cardápio antes do
     * checkout, atendente/garçom — que nunca escolhem forma de pagamento ao
     * adicionar item), nenhuma restrição de pagamento é aplicada. Quando há
     * mais de uma regra vigente para o mesmo produto (duas campanhas
     * concorrentes), prevalece a de menor promoad_ordem.
     */
    public function regraAdicionalDoProduto(int $produtoGatilhoId, ?int $opcaoPagamentoId = null): ?PromocaoAdicionalRegra
    {
        return $this->regrasAdicionaisVigentes()
            ->filter(fn (PromocaoAdicionalRegra $regra) => (int) $regra->par_produto_gatilho_id === $produtoGatilhoId)
            ->filter(fn (PromocaoAdicionalRegra $regra) => $opcaoPagamentoId === null || $regra->promocao->pagamentoPermitido($opcaoPagamentoId))
            ->sortBy(fn (PromocaoAdicionalRegra $regra) => $regra->promocao->promoad_ordem)
            ->first();
    }

    /**
     * As opções de produto que podem ser oferecidas para este gatilho agora
     * (com saldo, respeitando forma de pagamento) — o cliente escolhe 1.
     *
     * @return Collection<int, PromocaoAdicionalOferta>
     */
    public function ofertasDoProduto(int $produtoGatilhoId, ?int $opcaoPagamentoId = null): Collection
    {
        $regra = $this->regraAdicionalDoProduto($produtoGatilhoId, $opcaoPagamentoId);

        return $regra ? $regra->ofertasDisponiveis()->values() : collect();
    }

    /** Esquece o cache de promoções (use após consumir saldo no mesmo request). */
    public function esquecerPromocoes(): void
    {
        $this->vigentes = null;
        $this->regrasAdicionais = null;
    }

    /**
     * Promoções relâmpago vigentes (com saldo) que contêm o produto.
     *
     * @return Collection<int, PromocaoRelampago>
     */
    public function promocoesVigentesDoProduto(int $produtoId): Collection
    {
        return $this->promocoesVigentes()
            ->filter(fn (PromocaoRelampago $promocao) => $promocao->promocaoProdutos
                ->contains(fn (PromocaoRelampagoProduto $prp) => (int) $prp->prp_produto_id === $produtoId && ! $prp->esgotado()))
            ->values();
    }

    /**
     * Melhor oferta relâmpago para um produto: entre as promoções vigentes com
     * saldo (pool e sublimite), a de menor preço.
     */
    public function promocaoDoProduto(int $produtoId): ?PromocaoRelampagoProduto
    {
        return $this->promocoesVigentes()
            ->flatMap(fn (PromocaoRelampago $promocao) => $promocao->promocaoProdutos)
            ->filter(fn (PromocaoRelampagoProduto $prp) => (int) $prp->prp_produto_id === $produtoId && ! $prp->esgotado())
            ->sortBy('prp_preco_promocional')
            ->first();
    }

    /**
     * Preço de uma unidade vendida sozinha.
     *
     * @param  bool  $considerarRelampago  Falso ao reprecificar item já gravado:
     *                                     promoção relâmpago não se aplica
     *                                     retroativamente a um item antigo.
     * @param  bool  $considerarPromoAdicional  Falso ao reprecificar item já
     *                                          gravado: mesma razão do parâmetro
     *                                          acima — ver ItensPedido::recalcularValores().
     * @param  ?int  $opcaoPagamentoId  Forma de pagamento escolhida, quando já
     *                                  conhecida (só o checkout público sabe
     *                                  isso no momento de precificar). Null não
     *                                  filtra por pagamento — ver
     *                                  regraAdicionalDoProduto().
     */
    public function resolver(Produto $produto, bool $considerarRelampago = true, bool $considerarPromoAdicional = true, ?int $opcaoPagamentoId = null): PrecoResolvido
    {
        $precoVenda = (float) $produto->produto_preco_venda;

        if ($considerarPromoAdicional
            && ($regra = $this->regraAdicionalDoProduto($produto->id, $opcaoPagamentoId)) !== null
            && $regra->par_preco_gatilho_override !== null
        ) {
            $preco = (float) $regra->par_preco_gatilho_override;

            return new PrecoResolvido(
                valorUnitario: round(max($precoVenda, $preco), 2),
                descontoUnitario: $preco < $precoVenda ? round($precoVenda - $preco, 2) : 0.0,
                promocaoAdicionalRegraId: $regra->id,
            );
        }

        if ($considerarRelampago && ($prp = $this->promocaoDoProduto($produto->id)) !== null) {
            $preco = (float) $prp->prp_preco_promocional;

            return new PrecoResolvido(
                valorUnitario: round(max($precoVenda, $preco), 2),
                descontoUnitario: $preco < $precoVenda ? round($precoVenda - $preco, 2) : 0.0,
                promocaoId: (int) $prp->prp_promocao_id,
                promocaoProdutoId: (int) $prp->id,
            );
        }

        $promo = $this->precoPromocionalVigenteDoProduto($produto);

        return new PrecoResolvido(
            valorUnitario: round(max($precoVenda, $promo), 2),
            descontoUnitario: ($promo > 0 && $promo < $precoVenda) ? round($precoVenda - $promo, 2) : 0.0,
        );
    }

    /**
     * produto_preco_promocional só vale dentro da janela de datas do produto.
     * Datas em branco significam promoção permanente — era o comportamento
     * efetivo antes desta correção, já que o cardápio ignorava as duas colunas.
     */
    public function precoPromocionalVigenteDoProduto(Produto $produto, ?Carbon $hoje = null): float
    {
        $promo = (float) ($produto->produto_preco_promocional ?? 0);

        if ($promo <= 0) {
            return 0.0;
        }

        $hoje ??= Carbon::today();

        $inicio = $produto->produto_data_inicio_promocao;
        $fim = $produto->produto_data_final_promocao;

        if ($inicio && $hoje->lt(Carbon::parse($inicio)->startOfDay())) {
            return 0.0;
        }

        if ($fim && $hoje->gt(Carbon::parse($fim)->startOfDay())) {
            return 0.0;
        }

        return $promo;
    }

    /**
     * Promoção que cobre um combo de sabores. Só se aplica quando TODOS os
     * sabores pertencem à mesma promoção vigente — meia calabresa promocional
     * com meia portuguesa comum sai pelo preço normal.
     *
     * @param  array<int, Produto>  $produtos
     */
    public function promocaoDoCombo(array $produtos): ?PromocaoRelampago
    {
        $total = count($produtos);

        if ($total < 2) {
            return null;
        }

        foreach ($this->promocoesVigentes() as $promocao) {
            if (! $promocao->promocao_permite_sabores) {
                continue;
            }

            if ($total > $promocao->maxSaboresEfetivo($produtos)) {
                continue;
            }

            $cobreTodos = collect($produtos)->every(function (Produto $produto) use ($promocao) {
                $prp = $promocao->promocaoProdutos
                    ->firstWhere('prp_produto_id', $produto->id);

                return $prp !== null && ! $prp->esgotado();
            });

            if ($cobreTodos) {
                return $promocao;
            }
        }

        return null;
    }

    /**
     * Regras de promoção adicional que cobrem TODOS os sabores do combo —
     * só quando cada sabor tem override de preço configurado, todos na MESMA
     * campanha, e essa campanha permite fracionamento
     * (promoad_aplica_fracionado). Qualquer uma dessas condições faltando, o
     * combo cai no preço cheio normal (mesmo comportamento de antes deste
     * parâmetro existir). Chamada só quando não há promoção RELÂMPAGO de
     * combo cobrindo os mesmos sabores (relâmpago tem prioridade).
     *
     * @param  array<int, Produto>  $produtos
     * @return Collection<int, PromocaoAdicionalRegra>|null keyed pelo produto_id
     */
    public function regrasAdicionaisDoCombo(array $produtos, ?int $opcaoPagamentoId = null): ?Collection
    {
        if (count($produtos) < 2) {
            return null;
        }

        $regras = collect();

        foreach ($produtos as $produto) {
            $regra = $this->regraAdicionalDoProduto($produto->id, $opcaoPagamentoId);

            if ($regra === null || $regra->par_preco_gatilho_override === null) {
                return null;
            }

            $regras[$produto->id] = $regra;
        }

        $campanhaIds = $regras->pluck('par_promocao_id')->unique();

        if ($campanhaIds->count() !== 1 || ! $regras->first()->promocao->promoad_aplica_fracionado) {
            return null;
        }

        return $regras;
    }

    /**
     * Rateia uma pizza de N sabores em N linhas de item de pedido, preservando
     * a convenção existente: quantidade fracionada (1/N) e valor_unitario com o
     * preço cheio do sabor.
     *
     * Sob promoção, a pizza inteira custa o MAIOR preço promocional entre os
     * sabores escolhidos (preço anunciado; a média produziria um valor que
     * nunca foi divulgado). O desconto resultante é rateado entre os sabores em
     * proporção ao preço cheio de cada um, o que garante desconto nunca
     * negativo e soma exata em centavos.
     *
     * @param  array<int, Produto>  $produtos  Sabores, na ordem escolhida
     * @param  int  $qtd  Quantidade de pizzas
     * @param  ?int  $opcaoPagamentoId  Ver resolver() — só o checkout público sabe a forma de pagamento neste momento.
     * @return array<int, array{produto_id: int, quantidade: float, valor_unitario: float, desconto: float, desconto_unitario: float, valor: float, promocao_id: ?int, promocao_adicional_regra_id: ?int}>
     */
    public function ratearCombo(array $produtos, int $qtd, ?int $opcaoPagamentoId = null): array
    {
        $numSabores = count($produtos);
        $promocao = $this->promocaoDoCombo($produtos);
        // Só entra em jogo quando não há combo relâmpago cobrindo os mesmos
        // sabores — relâmpago tem prioridade, igual ao item avulso.
        $regrasAdicionais = $promocao === null ? $this->regrasAdicionaisDoCombo($produtos, $opcaoPagamentoId) : null;

        // Preço cheio de cada sabor, em centavos, já dividido entre os N sabores.
        // Sob promoção o preço cheio é sempre o de venda: o promocional do
        // produto não se acumula com o da promoção relâmpago/adicional.
        $valorUnitario = [];
        $brutoUnitCents = [];
        $descontoUnitCents = [];

        foreach ($produtos as $idx => $produto) {
            // Sem promoção de combo cobrindo todos os sabores, cada um cai no
            // preço do produto — nunca no relâmpago nem no override de
            // promoção adicional isoladamente (daria o desconto sem respeitar
            // a regra de "todos os sabores cobertos").
            $preco = ($promocao !== null || $regrasAdicionais !== null)
                ? new PrecoResolvido(round((float) $produto->produto_preco_venda, 2), 0.0)
                : $this->resolver($produto, considerarRelampago: false, considerarPromoAdicional: false);

            $valorUnitario[$idx] = $preco->valorUnitario;
            $brutoUnitCents[$idx] = RateioCentavos::fatiaCentavos($preco->valorUnitario, $numSabores, $idx);
            $descontoUnitCents[$idx] = ($promocao !== null || $regrasAdicionais !== null)
                ? 0
                : RateioCentavos::fatiaCentavos($preco->descontoUnitario, $numSabores, $idx);
        }

        if ($promocao !== null) {
            $precoPizzaCents = (int) round($this->precoDoComboPromocional($promocao, $produtos) * 100);
            $descontoTotalCents = max(0, array_sum($brutoUnitCents) - $precoPizzaCents);
            $descontoUnitCents = RateioCentavos::ratearProporcional($descontoTotalCents, $brutoUnitCents);
        } elseif ($regrasAdicionais !== null) {
            $precoPizzaCents = (int) round($this->precoDoComboPromocionalAdicional($regrasAdicionais) * 100);
            $descontoTotalCents = max(0, array_sum($brutoUnitCents) - $precoPizzaCents);
            $descontoUnitCents = RateioCentavos::ratearProporcional($descontoTotalCents, $brutoUnitCents);
        }

        // Quantidade: 1/N por sabor, em centésimos, somando exatamente $qtd.
        $qtdCentesimos = $qtd * 100;
        $qtdPorItem = intdiv($qtdCentesimos, $numSabores);
        $qtdExtra = $qtdCentesimos % $numSabores;

        $linhas = [];

        foreach ($produtos as $idx => $produto) {
            $quantidade = ($qtdPorItem + ($idx >= $numSabores - $qtdExtra ? 1 : 0)) / 100;

            $bruto = round($brutoUnitCents[$idx] / 100 * $qtd, 2);
            $desconto = round($descontoUnitCents[$idx] / 100 * $qtd, 2);

            $linhas[] = [
                'produto_id' => $produto->id,
                'quantidade' => $quantidade,
                'valor_unitario' => $valorUnitario[$idx],
                'desconto' => $desconto,
                'desconto_unitario' => $quantidade > 0 ? round($desconto / $quantidade, 2) : 0.0,
                'valor' => round($bruto - $desconto, 2),
                'promocao_id' => $promocao?->id,
                'promocao_adicional_regra_id' => $regrasAdicionais?->get($produto->id)?->id,
            ];
        }

        return $linhas;
    }

    /**
     * Preço anunciado da pizza promocional montada com estes sabores: o maior
     * entre os preços promocionais dos sabores escolhidos.
     *
     * @param  array<int, Produto>  $produtos
     */
    private function precoDoComboPromocional(PromocaoRelampago $promocao, array $produtos): float
    {
        return collect($produtos)
            ->map(fn (Produto $produto) => (float) $promocao->promocaoProdutos
                ->firstWhere('prp_produto_id', $produto->id)
                ->prp_preco_promocional)
            ->max();
    }

    /**
     * Preço anunciado da pizza fracionada sob promoção adicional: o maior
     * override entre os sabores escolhidos — mesma regra de precoDoComboPromocional().
     *
     * @param  Collection<int, PromocaoAdicionalRegra>  $regras  keyed pelo produto_id
     */
    private function precoDoComboPromocionalAdicional(Collection $regras): float
    {
        return $regras->map(fn (PromocaoAdicionalRegra $regra) => (float) $regra->par_preco_gatilho_override)->max();
    }
}
