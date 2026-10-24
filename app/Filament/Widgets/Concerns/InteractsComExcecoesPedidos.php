<?php

namespace App\Filament\Widgets\Concerns;

use App\Services\RelatorioExcecoesPedidosService;
use Carbon\Carbon;

/**
 * Entrega aos widgets do Relatório de Exceções o serviço do período filtrado
 * na página. Use junto com InteractsWithPageFilters.
 */
trait InteractsComExcecoesPedidos
{
    use InteractsComPeriodo;

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $periodo  sem valor, o período do filtro da página
     */
    protected function relatorio(?array $periodo = null): RelatorioExcecoesPedidosService
    {
        [$inicio, $fim] = $periodo ?? $this->periodo();

        return new RelatorioExcecoesPedidosService($inicio, $fim);
    }
}
