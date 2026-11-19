<?php

namespace App\Services\PromocaoCombo;

/** Resultado do motor: combos fechados e o que falta para fechar outros. */
final readonly class PlanoCombo
{
    /**
     * @param  list<AplicacaoCombo>  $aplicacoes
     * @param  list<PendenciaCombo>  $pendencias
     */
    public function __construct(
        public array $aplicacoes = [],
        public array $pendencias = [],
    ) {}

    /** @return array<string, int> chave da linha => desconto em centavos */
    public function descontoPorLinha(): array
    {
        $porLinha = [];

        foreach ($this->aplicacoes as $aplicacao) {
            foreach ($aplicacao->partes as $chave => $parte) {
                $porLinha[$chave] = ($porLinha[$chave] ?? 0) + $parte['desconto'];
            }
        }

        return $porLinha;
    }

    public function descontoTotalCentavos(): int
    {
        return array_sum(array_map(fn (AplicacaoCombo $aplicacao): int => $aplicacao->descontoCentavos(), $this->aplicacoes));
    }
}
