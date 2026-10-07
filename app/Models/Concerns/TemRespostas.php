<?php

namespace App\Models\Concerns;

/**
 * Item (de pedido ou de venda) com as respostas das perguntas do produto
 * ("Borda: Catupiry"), congeladas em JSON no momento do pedido: a pergunta,
 * as opções escolhidas e o valor de cada uma. O valor entra no total da linha
 * junto com os adicionais (coluna *_valor_adicionais), por unidade do item.
 *
 * O model que usa o trait define colunaRespostas().
 */
trait TemRespostas
{
    abstract protected function colunaRespostas(): string;

    /**
     * @return list<array{pergunta_id: int, pergunta: string, opcoes: list<array{id: int, nome: string, valor: float}>}>
     */
    public function respostas(): array
    {
        $respostas = $this->getAttribute($this->colunaRespostas());

        return is_array($respostas) ? array_values($respostas) : [];
    }

    /** Soma dos acréscimos das opções escolhidas, para UMA unidade do item. */
    public function valorUnitarioRespostas(): float
    {
        return round(collect($this->respostas())
            ->flatMap(fn (array $resposta) => $resposta['opcoes'])
            ->sum(fn (array $opcao) => (float) $opcao['valor']), 2);
    }

    /**
     * Uma resposta por linha — "Borda: Catupiry", "Molhos: Alho, Barbecue" —
     * para telas, comanda e pré-conta.
     *
     * @return list<string>
     */
    public function linhasRespostas(): array
    {
        return array_map(
            fn (array $resposta): string => $resposta['pergunta'].': '.implode(', ', array_column($resposta['opcoes'], 'nome')),
            $this->respostas(),
        );
    }

    /** Respostas numa linha só (NF-e, textos corridos); vazio sem respostas. */
    public function descricaoRespostas(): string
    {
        return implode(' / ', $this->linhasRespostas());
    }
}
