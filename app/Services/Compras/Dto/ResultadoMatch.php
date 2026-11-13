<?php

namespace App\Services\Compras\Dto;

use App\Models\FornecedorProduto;

/** Produto do estoque reconhecido para um item de NF-e (ver ProdutoMatcher). */
final readonly class ResultadoMatch
{
    public const DE_PARA = 'de_para';

    public const EAN_DE_PARA = 'ean_de_para';

    public const EAN_PRODUTO = 'ean_produto';

    public function __construct(
        public int $produtoId,
        public float $fatorConversao,
        public ?FornecedorProduto $vinculo,
        public string $criterio,
    ) {}

    /** Reconhecido pelo EAN do cadastro de produtos, ainda sem vínculo no de-para. */
    public function precisaGravarVinculo(): bool
    {
        return $this->vinculo === null;
    }
}
