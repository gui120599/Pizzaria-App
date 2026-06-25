<?php

namespace App\Enums;

/**
 * Motivos categorizados de cancelamento (pedido e venda).
 *
 * Categorizado de propósito: permite agrupar e medir as perdas no dashboard.
 * Ajuste/adicione casos conforme a operação — labels aparecem no select do PDV.
 */
enum MotivoCancelamentoEnum: string
{
    case CLIENTE_DESISTIU = 'cliente_desistiu';
    case ERRO_LANCAMENTO = 'erro_lancamento';
    case PRODUTO_INDISPONIVEL = 'produto_indisponivel';
    case DEMORA = 'demora';
    case DUPLICADO = 'duplicado';
    case PROBLEMA_PAGAMENTO = 'problema_pagamento';
    case ENDERECO_FORA_AREA = 'endereco_fora_area';
    case OUTRO = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::CLIENTE_DESISTIU => 'Cliente desistiu',
            self::ERRO_LANCAMENTO => 'Erro no lançamento',
            self::PRODUTO_INDISPONIVEL => 'Produto indisponível',
            self::DEMORA => 'Demora no atendimento',
            self::DUPLICADO => 'Pedido duplicado / teste',
            self::PROBLEMA_PAGAMENTO => 'Problema no pagamento',
            self::ENDERECO_FORA_AREA => 'Endereço fora da área',
            self::OUTRO => 'Outro',
        };
    }

    /**
     * value => label, para popular <select> e validações.
     *
     * @return array<string, string>
     */
    public static function paraSelect(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $m) => [$m->value => $m->label()])
            ->all();
    }
}
