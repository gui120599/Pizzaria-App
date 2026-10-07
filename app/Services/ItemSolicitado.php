<?php

namespace App\Services;

/**
 * O que o cliente/garçom pediu, sem nenhum valor: o preço é sempre resolvido
 * no servidor por LancamentoItemPedidoService::precificar().
 */
final class ItemSolicitado
{
    /**
     * @param  list<int>  $saboresIds  Dois ou mais sabores = pizza de sabores (uma linha); vazio = item comum
     * @param  list<int>  $adicionaisIds  Adicionais escolhidos (precisam estar vinculados ao produto)
     * @param  ?int  $ofertaProdutoId  Produto escolhido entre as ofertas de promoção adicional do item
     * @param  array<int, list<int>>  $respostas  pergunta_id => opções escolhidas
     */
    public function __construct(
        public readonly int $produtoId,
        public readonly float $quantidade = 1,
        public readonly array $saboresIds = [],
        public readonly array $adicionaisIds = [],
        public readonly ?string $observacao = null,
        public readonly ?int $ofertaProdutoId = null,
        public readonly ?int $clienteId = null,
        public readonly array $respostas = [],
    ) {}

    /**
     * Linha do carrinho do cardápio público (delivery e mesa), já validada no
     * formato. Um sabor só é item comum; a oferta não vale na pizza de sabores.
     *
     * @param  array{id: int|string, qty: int|string, sabores?: ?array<int, array{id: int|string}>, adicionais?: ?array<int, int|string>, observacao?: ?string, oferta_produto_id?: int|string|null, respostas?: ?array<int|string, array<int, int|string>>}  $item
     */
    public static function doCarrinho(array $item): self
    {
        $sabores = array_map(fn (array $sabor) => (int) $sabor['id'], $item['sabores'] ?? []);
        $ehMultiSabor = count($sabores) > 1;

        return new self(
            produtoId: (int) $item['id'],
            quantidade: (int) $item['qty'],
            saboresIds: $ehMultiSabor ? $sabores : [],
            adicionaisIds: array_map('intval', $item['adicionais'] ?? []),
            observacao: $item['observacao'] ?? null,
            ofertaProdutoId: ! $ehMultiSabor && ! empty($item['oferta_produto_id']) ? (int) $item['oferta_produto_id'] : null,
            respostas: collect($item['respostas'] ?? [])
                ->mapWithKeys(fn ($opcoes, $perguntaId) => [(int) $perguntaId => array_map('intval', (array) $opcoes)])
                ->all(),
        );
    }

    /** O mesmo pedido lançado para outra pessoa (ex.: o cliente identificado no QR da mesa). */
    public function paraCliente(?int $clienteId): self
    {
        return new self(
            $this->produtoId,
            $this->quantidade,
            $this->saboresIds,
            $this->adicionaisIds,
            $this->observacao,
            $this->ofertaProdutoId,
            $clienteId,
            $this->respostas,
        );
    }

    public function ehMultiSabor(): bool
    {
        return count($this->saboresIds) > 1;
    }
}
