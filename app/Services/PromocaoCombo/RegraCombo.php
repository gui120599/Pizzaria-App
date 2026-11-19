<?php

namespace App\Services\PromocaoCombo;

/**
 * Promoção combo já resolvida para o motor: participantes com as
 * subcategorias expandidas e valores em centavos.
 */
final readonly class RegraCombo
{
    /**
     * @param  list<int>  $categoriasIncluidas
     * @param  list<int>  $produtosIncluidos
     * @param  list<int>  $categoriasExcluidas
     * @param  list<int>  $produtosExcluidos
     */
    public function __construct(
        public int $promocaoId,
        public string $nome,
        public int $quantidade,
        public int $precoCentavos,
        public int $prioridade = 0,
        public ?int $limitePorPedido = null,
        public bool $aplicaFracionado = true,
        public bool $cumulaRelampago = true,
        public bool $cumulaPromoAdicional = true,
        public bool $sugerir = true,
        public array $categoriasIncluidas = [],
        public array $produtosIncluidos = [],
        public array $categoriasExcluidas = [],
        public array $produtosExcluidos = [],
    ) {}

    /** A linha entra nesta promoção (na pizza de sabores, todos os sabores precisam participar). */
    public function aceita(LinhaCombo $linha): bool
    {
        if ($linha->produtos === []
            || ($linha->multiSabor() && ! $this->aplicaFracionado)
            || ($linha->relampago && ! $this->cumulaRelampago)
            || ($linha->promoAdicional && ! $this->cumulaPromoAdicional)) {
            return false;
        }

        foreach ($linha->produtos as $produto) {
            if (! $this->participa($produto['produto_id'], $produto['categoria_id'])) {
                return false;
            }
        }

        return true;
    }

    private function participa(int $produtoId, ?int $categoriaId): bool
    {
        if (in_array($produtoId, $this->produtosExcluidos, true)
            || ($categoriaId !== null && in_array($categoriaId, $this->categoriasExcluidas, true))) {
            return false;
        }

        return in_array($produtoId, $this->produtosIncluidos, true)
            || ($categoriaId !== null && in_array($categoriaId, $this->categoriasIncluidas, true));
    }
}
