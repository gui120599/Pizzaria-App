<?php

namespace App\Services\PromocaoCombo;

/** Faltam itens para fechar (mais) um combo: base do convite, que mostra só os grupos que faltam. */
final readonly class PendenciaCombo
{
    /**
     * @param  array<int, int>  $faltamPorGrupo  id do grupo => itens que faltam nele
     */
    public function __construct(
        public int $promocaoId,
        public string $nome,
        public int $quantidade,
        public int $precoCentavos,
        public int $faltam,
        public bool $sugerir,
        public array $faltamPorGrupo = [],
    ) {}
}
