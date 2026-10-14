<?php

namespace App\Support\Cozinha;

final readonly class PassoFicha
{
    public function __construct(
        public int $ordem,
        public string $titulo,
        public ?string $descricao,
        public ?string $foto,
        public ?int $tempoSegundos,
        public ?string $temperaturaAlvo,
        public bool $pontoCritico,
    ) {}
}
