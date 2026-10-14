<?php

namespace App\Support\Cozinha;

/** Uma linha de ingrediente já calculada para o item (fração do sabor aplicada). */
final readonly class IngredienteFicha
{
    public function __construct(
        public string $grupo,
        public string $nome,
        public float $quantidade,
        public string $quantidadeFormatada,
        public ?string $escopo,
        public bool $removido = false,
        public ?string $fotoPorcao = null,
        public ?string $observacao = null,
        /** Produto da subficha (massa, molho): a linha abre o preparo dele. */
        public ?int $subfichaProdutoId = null,
    ) {}
}
