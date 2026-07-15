<?php

namespace App\Filament\Widgets\Concerns;

use App\Models\ItensVenda;
use App\Models\Venda;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lê o período selecionado no filtro de um dashboard e oferece helpers de
 * formatação. Use junto com InteractsWithPageFilters, que expõe a
 * propriedade $pageFilters preenchida pela página.
 */
trait InteractsComPeriodo
{
    /**
     * Retorna [início, fim] como Carbon. Sem filtro, assume o mês corrente.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function periodo(): array
    {
        $filtros = $this->pageFilters ?? [];

        $inicio = ! empty($filtros['inicio'])
            ? Carbon::parse($filtros['inicio'])->startOfDay()
            : now()->startOfMonth();

        $fim = ! empty($filtros['fim'])
            ? Carbon::parse($filtros['fim'])->endOfDay()
            : now()->endOfDay();

        return [$inicio, $fim];
    }

    protected function brl(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }

    protected function pct(float $valor): string
    {
        return number_format($valor, 1, ',', '.').'%';
    }

    protected function minutos(float $valor): string
    {
        return number_format($valor, 0, ',', '.').' min';
    }

    /**
     * IDs de `opcoes_entregas` selecionados no filtro "Tipo de entrega".
     * Vazio = sem filtro (todos os tipos).
     *
     * @return array<int>
     */
    protected function tiposEntregaSelecionados(): array
    {
        return array_values(array_filter((array) ($this->pageFilters['tipos_entrega'] ?? [])));
    }

    /**
     * IDs de `categorias` selecionados no filtro "Categorias".
     *
     * @return array<int>
     */
    protected function categoriasSelecionadas(): array
    {
        return array_values(array_filter((array) ($this->pageFilters['categorias'] ?? [])));
    }

    /**
     * IDs de `produtos` selecionados no filtro "Produtos".
     *
     * @return array<int>
     */
    protected function produtosSelecionados(): array
    {
        return array_values(array_filter((array) ($this->pageFilters['produtos'] ?? [])));
    }

    /** Há filtro de categoria e/ou produto ativo? */
    protected function filtrandoPorProduto(): bool
    {
        return $this->categoriasSelecionadas() !== [] || $this->produtosSelecionados() !== [];
    }

    /**
     * Vendas finalizadas no período, com os filtros de tipo de entrega (e,
     * opcionalmente, categoria/produto) aplicados via whereHas — não altera o
     * valor somado da venda, só restringe quais vendas entram no cálculo.
     * Use para KPIs cujo valor é da venda inteira (nº de vendas, descontos).
     */
    protected function vendasFiltradasQuery(Carbon $inicio, Carbon $fim, bool $comFiltroProduto = true): Builder
    {
        $query = Venda::query()
            ->where('venda_status', 'FINALIZADA')
            ->whereBetween('venda_datahora_finalizada', [$inicio, $fim]);

        $tipos = $this->tiposEntregaSelecionados();
        if ($tipos !== []) {
            $query->whereHas('pedidos', fn ($q) => $q->whereIn('pedido_opcaoentrega_id', $tipos));
        }

        if ($comFiltroProduto && $this->filtrandoPorProduto()) {
            $categorias = $this->categoriasSelecionadas();
            $produtosIds = $this->produtosSelecionados();

            $query->whereHas('itensVenda', function ($q) use ($categorias, $produtosIds) {
                $q->where('item_venda_status', 'INSERIDO')
                    ->whereHas('produto', function ($q2) use ($categorias, $produtosIds) {
                        if ($categorias !== []) {
                            $q2->whereIn('produto_categoria_id', $categorias);
                        }
                        if ($produtosIds !== []) {
                            $q2->whereIn('id', $produtosIds);
                        }
                    });
            });
        }

        return $query;
    }

    /**
     * Itens de venda (join vendas+produtos) no período, com todos os filtros do
     * dashboard aplicados (tipo de entrega, categoria, produto). Use para KPIs
     * atribuíveis a um item (receita/custo/quantidade de produtos filtrados).
     */
    protected function itensFiltradosQuery(Carbon $inicio, Carbon $fim): Builder
    {
        $query = ItensVenda::query()
            ->join('vendas', 'vendas.id', '=', 'itens_vendas.item_venda_venda_id')
            ->join('produtos', 'produtos.id', '=', 'itens_vendas.item_venda_produto_id')
            ->where('vendas.venda_status', 'FINALIZADA')
            ->where('itens_vendas.item_venda_status', 'INSERIDO')
            ->whereBetween('vendas.venda_datahora_finalizada', [$inicio, $fim]);

        $categorias = $this->categoriasSelecionadas();
        $produtosIds = $this->produtosSelecionados();

        if ($categorias !== []) {
            $query->whereIn('produtos.produto_categoria_id', $categorias);
        }
        if ($produtosIds !== []) {
            $query->whereIn('produtos.id', $produtosIds);
        }

        $tipos = $this->tiposEntregaSelecionados();
        if ($tipos !== []) {
            $query->whereIn('vendas.id', function ($q) use ($tipos) {
                $q->select('pedido_venda_id')->from('pedidos')->whereIn('pedido_opcaoentrega_id', $tipos);
            });
        }

        return $query;
    }
}
