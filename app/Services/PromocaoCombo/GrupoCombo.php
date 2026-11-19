<?php

namespace App\Services\PromocaoCombo;

/**
 * Uma posição do combo já resolvida para o motor ("1 Pizza Grande"):
 * quantidade e participantes, com as subcategorias expandidas.
 */
final readonly class GrupoCombo
{
    /**
     * @param  list<int>  $categoriasIncluidas
     * @param  list<int>  $produtosIncluidos
     * @param  list<int>  $categoriasExcluidas
     * @param  list<int>  $produtosExcluidos
     */
    public function __construct(
        public int $id,
        public string $nome,
        public int $quantidade,
        public array $categoriasIncluidas = [],
        public array $produtosIncluidos = [],
        public array $categoriasExcluidas = [],
        public array $produtosExcluidos = [],
    ) {}

    /** Na pizza de sabores, todos os sabores precisam participar do grupo. */
    public function aceita(LinhaCombo $linha): bool
    {
        if ($linha->produtos === []) {
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
