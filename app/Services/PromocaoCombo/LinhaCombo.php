<?php

namespace App\Services\PromocaoCombo;

/**
 * Uma linha do pedido (gravada ou de prévia) vista pelo motor da promoção
 * combo: só o que decide elegibilidade e o valor de referência da unidade.
 */
final readonly class LinhaCombo
{
    /**
     * @param  string  $chave  id da linha (ou chave da prévia)
     * @param  int  $ordem  ordem de inclusão no pedido (a 1ª e a 2ª formam o combo, a 3ª sai cheia)
     * @param  int  $unidades  unidades livres para combo (quantidade inteira da linha)
     * @param  int  $baseUnitariaCentavos  preço de uma unidade sem o combo e sem adicionais
     * @param  list<array{produto_id: int, categoria_id: ?int}>  $produtos  o produto, ou cada sabor da pizza
     * @param  bool  $relampago  a linha já está em promoção relâmpago
     * @param  bool  $promoAdicional  a linha é gatilho de Promoção Adicional (preço override ou brinde)
     */
    public function __construct(
        public string $chave,
        public int $ordem,
        public int $unidades,
        public int $baseUnitariaCentavos,
        public array $produtos,
        public bool $relampago = false,
        public bool $promoAdicional = false,
    ) {}

    public function multiSabor(): bool
    {
        return count($this->produtos) > 1;
    }
}
