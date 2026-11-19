<?php

namespace App\Services\PromocaoCombo;

/**
 * Promoção combo já resolvida para o motor: os grupos (posições) e as
 * condições, com valores em centavos.
 */
final readonly class RegraCombo
{
    /**
     * @param  list<GrupoCombo>  $grupos
     */
    public function __construct(
        public int $promocaoId,
        public string $nome,
        public int $precoCentavos,
        public array $grupos,
        public int $prioridade = 0,
        public ?int $limitePorPedido = null,
        public bool $aplicaFracionado = true,
        public bool $cumulaRelampago = true,
        public bool $cumulaPromoAdicional = true,
        public bool $sugerir = true,
    ) {}

    /** Itens por combo: a soma das quantidades dos grupos. */
    public function quantidade(): int
    {
        return array_sum(array_map(fn (GrupoCombo $grupo): int => $grupo->quantidade, $this->grupos));
    }

    /** A linha pode entrar em algum grupo desta promoção. */
    public function aceita(LinhaCombo $linha): bool
    {
        return $this->gruposQueAceitam($linha) !== [];
    }

    /**
     * Índices dos grupos em que a linha cabe (as condições da promoção valem para todos).
     *
     * @return list<int>
     */
    public function gruposQueAceitam(LinhaCombo $linha): array
    {
        if (($linha->multiSabor() && ! $this->aplicaFracionado)
            || ($linha->relampago && ! $this->cumulaRelampago)
            || ($linha->promoAdicional && ! $this->cumulaPromoAdicional)) {
            return [];
        }

        return array_keys(array_filter($this->grupos, fn (GrupoCombo $grupo): bool => $grupo->quantidade > 0 && $grupo->aceita($linha)));
    }
}
