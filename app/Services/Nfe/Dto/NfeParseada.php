<?php

namespace App\Services\Nfe\Dto;

use Carbon\CarbonImmutable;

/** Resultado do parsing de um XML de NF-e de compra, pronto para persistência. */
final class NfeParseada
{
    /** @param  NfeItem[]  $itens */
    public function __construct(
        public readonly string $chaveAcesso,
        public readonly ?string $numero,
        public readonly ?string $serie,
        public readonly ?CarbonImmutable $dataEmissao,
        public readonly bool $modeloReconhecido,
        public readonly bool $autorizacaoConfirmada,
        public readonly NfeEmitente $emitente,
        public readonly float $valorProdutos,
        public readonly float $valorFrete,
        public readonly float $valorDesconto,
        public readonly float $valorOutros,
        public readonly float $valorTotalNota,
        public readonly array $itens,
    ) {}

    /** Soma dos descontos informados item a item (vDesc de cada <det>). */
    public function valorDescontoItens(): float
    {
        return round(array_sum(array_map(fn (NfeItem $item) => $item->valorDesconto, $this->itens)), 2);
    }

    /**
     * Desconto que ainda precisa ser rateado no cabeçalho da compra: quando o
     * desconto total da nota já está distribuído entre os itens (vDesc por
     * item soma o vDesc do ICMSTot), não há nada a ratear de novo — evita
     * contar o desconto duas vezes (uma no custo do item, outra no rateio).
     */
    public function valorDescontoParaRateio(): float
    {
        $diferenca = round($this->valorDesconto - $this->valorDescontoItens(), 2);

        return max($diferenca, 0.0);
    }

    /** Avisos não-bloqueantes para revisão manual (não impedem a importação). */
    public function avisos(): array
    {
        $avisos = [];

        if (! $this->modeloReconhecido) {
            $avisos[] = 'XML não identifica explicitamente NF-e modelo 55 — confira o documento antes de confirmar.';
        }

        if (! $this->autorizacaoConfirmada) {
            $avisos[] = 'XML sem protocolo de autorização (protNFe) — não foi possível confirmar o status junto à SEFAZ com este arquivo.';
        }

        return $avisos;
    }
}
