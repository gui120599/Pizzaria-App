<?php

namespace App\Services;

use App\Models\Produto;
use App\Models\PromocaoRelampago;
use App\Models\PromocaoRelampagoProduto;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Fonte única do preço de venda de um produto. O preço NUNCA vem do cliente:
 * quem decide é o servidor, consultando, nesta ordem de precedência:
 *
 *   1. promoção relâmpago vigente e com saldo (preço no pivô);
 *   2. produto_preco_promocional, respeitando a janela de datas do produto;
 *   3. produto_preco_venda.
 *
 * As promoções vigentes são memoizadas por instância — o serviço é resolvido
 * por requisição, então o carrinho inteiro enxerga o mesmo conjunto. O saldo
 * lido aqui é indicativo; quem garante o limite sob concorrência é o UPDATE
 * condicional em PromocaoRelampagoService.
 */
class PrecificadorService
{
    /** @var Collection<int, PromocaoRelampago>|null */
    private ?Collection $vigentes = null;

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

    /** Esquece o cache de promoções (use após consumir saldo no mesmo request). */
    public function esquecerPromocoes(): void
    {
        $this->vigentes = null;
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
     */
    public function resolver(Produto $produto, bool $considerarRelampago = true): PrecoResolvido
    {
        $precoVenda = (float) $produto->produto_preco_venda;

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
     * @return array<int, array{produto_id: int, quantidade: float, valor_unitario: float, desconto: float, desconto_unitario: float, valor: float, promocao_id: ?int}>
     */
    public function ratearCombo(array $produtos, int $qtd): array
    {
        $numSabores = count($produtos);
        $promocao = $this->promocaoDoCombo($produtos);

        // Preço cheio de cada sabor, em centavos, já dividido entre os N sabores.
        // Sob promoção o preço cheio é sempre o de venda: o promocional do
        // produto não se acumula com o da promoção relâmpago.
        $valorUnitario = [];
        $brutoUnitCents = [];
        $descontoUnitCents = [];

        foreach ($produtos as $idx => $produto) {
            // Sem promoção de combo, cada sabor cai no preço do produto — nunca no
            // relâmpago: aplicá-lo aqui daria o desconto sem debitar o contador.
            $preco = $promocao !== null
                ? new PrecoResolvido(round((float) $produto->produto_preco_venda, 2), 0.0)
                : $this->resolver($produto, considerarRelampago: false);

            $valorUnitario[$idx] = $preco->valorUnitario;
            $brutoUnitCents[$idx] = $this->fatiaCentavos($preco->valorUnitario, $numSabores, $idx);
            $descontoUnitCents[$idx] = $promocao !== null
                ? 0
                : $this->fatiaCentavos($preco->descontoUnitario, $numSabores, $idx);
        }

        if ($promocao !== null) {
            $precoPizzaCents = (int) round($this->precoDoComboPromocional($promocao, $produtos) * 100);
            $descontoTotalCents = max(0, array_sum($brutoUnitCents) - $precoPizzaCents);
            $descontoUnitCents = $this->ratearProporcional($descontoTotalCents, $brutoUnitCents);
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

    /** Fatia $valor em $n partes de centavos; o resto vai para as primeiras fatias. */
    private function fatiaCentavos(float $valor, int $n, int $idx): int
    {
        $cents = (int) round($valor * 100);
        $base = intdiv($cents, $n);
        $resto = $cents % $n;

        return $base + ($idx < $resto ? 1 : 0);
    }

    /**
     * Distribui $totalCents proporcionalmente a $pesos, sem perder centavos:
     * o resto do arredondamento vai para os maiores pesos.
     *
     * @param  array<int, int>  $pesos
     * @return array<int, int>
     */
    private function ratearProporcional(int $totalCents, array $pesos): array
    {
        $somaPesos = array_sum($pesos);

        if ($somaPesos <= 0 || $totalCents <= 0) {
            return array_fill_keys(array_keys($pesos), 0);
        }

        $rateio = [];
        $distribuido = 0;

        foreach ($pesos as $idx => $peso) {
            $rateio[$idx] = intdiv($totalCents * $peso, $somaPesos);
            $distribuido += $rateio[$idx];
        }

        // Sobra de arredondamento: no máximo count($pesos) - 1 centavos.
        $sobra = $totalCents - $distribuido;
        $ordemPorPeso = array_keys($pesos);
        usort($ordemPorPeso, fn (int $a, int $b) => $pesos[$b] <=> $pesos[$a]);

        foreach ($ordemPorPeso as $idx) {
            if ($sobra <= 0) {
                break;
            }
            $rateio[$idx]++;
            $sobra--;
        }

        return $rateio;
    }
}
