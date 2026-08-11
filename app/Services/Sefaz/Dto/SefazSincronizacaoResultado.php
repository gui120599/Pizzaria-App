<?php

namespace App\Services\Sefaz\Dto;

/** Contadores de uma sincronização da caixa de entrada de NF-e (sem manifestar/importar nada). */
final class SefazSincronizacaoResultado
{
    public function __construct(
        public readonly int $novas = 0,
        public readonly int $atualizadas = 0,
        public readonly int $puladas = 0,
    ) {}

    public function comIncremento(string $campo): self
    {
        $valores = [
            'novas' => $this->novas,
            'atualizadas' => $this->atualizadas,
            'puladas' => $this->puladas,
        ];
        $valores[$campo]++;

        return new self(...$valores);
    }
}
