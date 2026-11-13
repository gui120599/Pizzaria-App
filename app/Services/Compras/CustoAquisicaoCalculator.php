<?php

namespace App\Services\Compras;

use App\Models\Compra;
use App\Models\CompraItem;
use Illuminate\Support\Collection;

/**
 * Custo de aquisição de cada item de uma compra (regra 12 da spec de compras
 * automatizadas), na unidade de estoque.
 *
 * Com `compras.custo.incluir_tributos_nao_recuperaveis` = false (padrão),
 * reproduz exatamente o cálculo histórico do CompraService::confirmar():
 * rateio de (frete + outros − desconto do cabeçalho) proporcional ao valor
 * dos produtos, 4 casas, sem reconciliar resíduo.
 *
 * Com true, cada item soma o próprio frete/seguro/outras despesas (ou a sua
 * parte do total da nota, se ela só trouxer no cabeçalho), menos o desconto
 * não distribuído, mais IPI, ICMS-ST e FCP-ST do item. O resíduo de
 * arredondamento dos rateios vai para o item de maior valor, para a soma
 * bater com o total da nota.
 */
class CustoAquisicaoCalculator
{
    public function incluiTributos(): bool
    {
        return (bool) config('compras.custo.incluir_tributos_nao_recuperaveis', false);
    }

    /**
     * Acréscimos do cabeçalho rateados sobre os itens (o que soma ao valor
     * dos produtos para chegar ao total da compra).
     */
    public function acrescimosDaCompra(Compra $compra): float
    {
        $acrescimos = (float) $compra->compra_valor_frete
            + (float) $compra->compra_valor_outros
            - (float) $compra->compra_valor_desconto;

        if ($this->incluiTributos()) {
            $acrescimos += (float) $compra->compra_valor_seguro
                + (float) $compra->compra_valor_st
                + (float) $compra->compra_valor_ipi
                + (float) $compra->compra_valor_fcp_st;
        }

        return $acrescimos;
    }

    /**
     * @param  Collection<int, CompraItem>  $itens
     * @return array<int, array{rateio: float, total: float, unitario: float}> por id do item
     */
    public function calcular(Compra $compra, Collection $itens): array
    {
        return $this->incluiTributos()
            ? $this->completo($compra, $itens)
            : $this->historico($compra, $itens);
    }

    /**
     * @param  Collection<int, CompraItem>  $itens
     * @return array<int, array{rateio: float, total: float, unitario: float}>
     */
    private function historico(Compra $compra, Collection $itens): array
    {
        $base = $itens->sum(fn (CompraItem $item): float => $item->valorProdutos());
        $rateavel = $this->acrescimosDaCompra($compra);

        $resultado = [];
        foreach ($itens as $item) {
            $rateio = $base > 0 ? round($rateavel * ($item->valorProdutos() / $base), 4) : 0.0;
            $resultado[$item->id] = $this->linha($item, $rateio);
        }

        return $resultado;
    }

    /**
     * @param  Collection<int, CompraItem>  $itens
     * @return array<int, array{rateio: float, total: float, unitario: float}>
     */
    private function completo(Compra $compra, Collection $itens): array
    {
        $itens = $itens->values();
        $acrescimos = array_fill(0, $itens->count(), 0.0);

        $componentes = [
            ['ci_valor_frete', (float) $compra->compra_valor_frete],
            ['ci_valor_seguro', (float) $compra->compra_valor_seguro],
            ['ci_valor_outros', (float) $compra->compra_valor_outros],
        ];

        foreach ($componentes as [$coluna, $totalNota]) {
            $doItem = $itens->map(fn (CompraItem $item): float => (float) $item->{$coluna})->all();
            $restante = round($totalNota - array_sum($doItem), 2);
            $rateado = $this->ratear($itens, $restante);

            foreach ($doItem as $indice => $valor) {
                $acrescimos[$indice] += $valor + $rateado[$indice];
            }
        }

        // Desconto do cabeçalho: o do item já está líquido no custo unitário.
        foreach ($this->ratear($itens, -(float) $compra->compra_valor_desconto) as $indice => $valor) {
            $acrescimos[$indice] += $valor;
        }

        $resultado = [];
        foreach ($itens as $indice => $item) {
            $tributos = (float) $item->ci_valor_ipi + (float) $item->ci_valor_st + (float) $item->ci_valor_fcp_st;
            $resultado[$item->id] = $this->linha($item, round($acrescimos[$indice] + $tributos, 4));
        }

        return $resultado;
    }

    /**
     * Distribui $valor proporcionalmente ao valor dos produtos, em centavos,
     * com o resíduo no item de maior valor.
     *
     * @param  Collection<int, CompraItem>  $itens
     * @return list<float>
     */
    private function ratear(Collection $itens, float $valor): array
    {
        $pesos = $itens->map(fn (CompraItem $item): float => $item->valorProdutos())->all();
        $base = array_sum($pesos);

        if (abs($valor) < 0.005 || $base <= 0) {
            return array_fill(0, count($pesos), 0.0);
        }

        $partes = array_map(fn (float $peso): float => round($valor * $peso / $base, 2), $pesos);
        $residuo = round($valor - array_sum($partes), 2);

        if (abs($residuo) >= 0.01) {
            $maior = array_keys($pesos, max($pesos))[0];
            $partes[$maior] = round($partes[$maior] + $residuo, 2);
        }

        return $partes;
    }

    /** @return array{rateio: float, total: float, unitario: float} */
    private function linha(CompraItem $item, float $rateio): array
    {
        $total = $item->valorProdutos() + $rateio;
        $quantidade = $item->quantidadeEstoque();

        return [
            'rateio' => $rateio,
            'total' => round($total, 2),
            'unitario' => $quantidade > 0 ? round($total / $quantidade, 4) : 0.0,
        ];
    }
}
