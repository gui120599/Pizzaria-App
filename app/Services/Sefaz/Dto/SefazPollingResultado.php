<?php

namespace App\Services\Sefaz\Dto;

/** Contadores de uma execução do polling por NSU, pro command exibir/logar o resumo. */
final class SefazPollingResultado
{
    public function __construct(
        public readonly int $importadas = 0,
        public readonly int $puladas = 0,
        public readonly int $pendentes = 0,
        public readonly int $erros = 0,
    ) {}

    public function comIncremento(string $campo): self
    {
        $valores = [
            'importadas' => $this->importadas,
            'puladas' => $this->puladas,
            'pendentes' => $this->pendentes,
            'erros' => $this->erros,
        ];
        $valores[$campo]++;

        return new self(...$valores);
    }
}
