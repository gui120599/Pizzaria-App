<?php

namespace App\Filament\Widgets\Concerns;

use App\Services\RelatorioContasPagarReceberService;

/**
 * Lê os filtros selecionados em RelatorioContasPagarReceber (via $pageFilters,
 * preenchido pelo Filament\Pages\Dashboard\Concerns\HasFiltersForm) e devolve o
 * Service já instanciado. Use junto com InteractsWithPageFilters.
 *
 * Mesmo papel do InteractsComPeriodo usado no FinanceiroDashboard, mas para o
 * relatório de Contas a Pagar/Receber.
 */
trait InteractsComFiltrosContasPagarReceber
{
    protected function relatorioService(): RelatorioContasPagarReceberService
    {
        return new RelatorioContasPagarReceberService($this->pageFilters ?? []);
    }

    protected function brl(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }

    protected function pct(float $valor): string
    {
        return number_format($valor, 1, ',', '.').'%';
    }

    protected function dias(float $valor): string
    {
        return number_format($valor, 0, ',', '.').' dias';
    }
}
