<?php

namespace App\Services\Ponto;

use App\Models\PontoAjuste;
use App\Models\PontoMarcacao;
use Carbon\CarbonImmutable;

/**
 * Uma marcação que vale para a apuração: a original não desconsiderada, ou
 * o horário incluído por um ajuste aprovado.
 */
final readonly class MarcacaoEfetiva
{
    public function __construct(
        public CarbonImmutable $momento,
        public ?PontoMarcacao $marcacao = null,
        public ?PontoAjuste $inclusao = null,
    ) {}

    public static function original(PontoMarcacao $marcacao): self
    {
        return new self(CarbonImmutable::instance($marcacao->registrada_em), $marcacao);
    }

    public static function incluida(PontoAjuste $ajuste): self
    {
        return new self(CarbonImmutable::instance($ajuste->data_hora), inclusao: $ajuste);
    }

    /** Veio de um ajuste (não é a batida original). */
    public function corrigida(): bool
    {
        return $this->inclusao !== null;
    }

    public function foraDaCerca(): bool
    {
        return $this->marcacao?->dentro_da_cerca === false;
    }
}
