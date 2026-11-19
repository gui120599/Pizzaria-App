<?php

namespace App\Services\PromocaoCombo;

/** Um conjunto de unidades que fechou uma promoção combo. Valores em centavos. */
final readonly class AplicacaoCombo
{
    /**
     * @param  array<string, array{unidades: int, cheio: int, desconto: int}>  $partes  chave da linha => parte dela
     */
    public function __construct(
        public int $promocaoId,
        public string $nome,
        public int $quantidade,
        public int $precoCentavos,
        public string $chave,
        public array $partes,
    ) {}

    public function valorCheioCentavos(): int
    {
        return array_sum(array_column($this->partes, 'cheio'));
    }

    public function descontoCentavos(): int
    {
        return array_sum(array_column($this->partes, 'desconto'));
    }
}
