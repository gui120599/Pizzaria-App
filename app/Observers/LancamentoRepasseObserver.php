<?php

namespace App\Observers;

use App\Models\Lancamento;
use App\Services\Ponto\RepasseService;

/**
 * Baixa (ou estorno, ou cancelamento) do título de um repasse no financeiro
 * reflete no repasse e no período (spec §5.5). Escuta o evento próprio
 * `statusAlterado` do Lancamento, que também cobre o recálculo silencioso
 * feito a partir dos pagamentos.
 */
class LancamentoRepasseObserver
{
    public function statusAlterado(Lancamento $lancamento): void
    {
        if ($lancamento->repasse_id !== null) {
            app(RepasseService::class)->sincronizarPagamento($lancamento);
        }
    }
}
