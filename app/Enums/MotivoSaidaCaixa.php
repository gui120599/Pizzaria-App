<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Motivo de uma movimentação MANUAL de caixa (registrada pelo operador, fora do
 * fluxo automático de venda/abertura) — `movimentacoes_sessao_caixas.mov_motivo`.
 * É o que distingue uma sangria/suprimento dos demais movimentos (venda, saldo
 * inicial de abertura, estorno Stone), que ficam sempre com `mov_motivo` nulo.
 * Ver App\Services\MovimentacaoCaixaService.
 */
enum MotivoSaidaCaixa: string implements HasColor, HasLabel
{
    case Sangria = 'sangria';
    case PagamentoDespesa = 'pagamento_despesa';
    case Suprimento = 'suprimento';
    case Outros = 'outros';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sangria => 'Sangria',
            self::PagamentoDespesa => 'Pagamento de despesa',
            self::Suprimento => 'Suprimento (reforço de troco)',
            self::Outros => 'Outros',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sangria, self::PagamentoDespesa => 'danger',
            self::Suprimento => 'success',
            self::Outros => 'gray',
        };
    }
}
