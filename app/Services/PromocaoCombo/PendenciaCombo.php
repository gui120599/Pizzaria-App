<?php

namespace App\Services\PromocaoCombo;

/** Faltam unidades para fechar (mais) um combo: base do modal de oportunidade. */
final readonly class PendenciaCombo
{
    public function __construct(
        public int $promocaoId,
        public string $nome,
        public int $quantidade,
        public int $precoCentavos,
        public int $faltam,
        public bool $sugerir,
    ) {}
}
