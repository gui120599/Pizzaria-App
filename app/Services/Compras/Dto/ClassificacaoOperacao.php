<?php

namespace App\Services\Compras\Dto;

use App\Enums\ConfiancaOperacaoEnum;
use App\Enums\OperacaoEntradaEnum;

/**
 * Resultado do ClassificadorOperacaoEntrada: operação sugerida no cabeçalho
 * (null + mista quando os itens divergem), confiança, critérios usados,
 * alertas e a operação de cada item.
 */
final readonly class ClassificacaoOperacao
{
    /**
     * @param  string[]  $criterios
     * @param  string[]  $alertas
     * @param  ClassificacaoItem[]  $porItem
     */
    public function __construct(
        public ?OperacaoEntradaEnum $operacao,
        public ConfiancaOperacaoEnum $confianca,
        public array $criterios,
        public array $alertas,
        public array $porItem,
        public bool $mista = false,
    ) {}

    /** Valor gravado em compras.compra_operacao: o enum, ou 'mista'. */
    public function valorCabecalho(): string
    {
        return $this->mista ? OperacaoEntradaEnum::MISTA : $this->operacao->value;
    }

    /** @return array{criterios: string[], alertas: string[]} */
    public function detalhes(): array
    {
        return ['criterios' => $this->criterios, 'alertas' => $this->alertas];
    }
}
