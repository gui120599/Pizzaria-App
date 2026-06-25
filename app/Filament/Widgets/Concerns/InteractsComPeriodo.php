<?php

namespace App\Filament\Widgets\Concerns;

use Carbon\Carbon;

/**
 * Lê o período selecionado no filtro de um dashboard e oferece helpers de
 * formatação. Use junto com InteractsWithPageFilters, que expõe a
 * propriedade $pageFilters preenchida pela página.
 */
trait InteractsComPeriodo
{
    /**
     * Retorna [início, fim] como Carbon. Sem filtro, assume o mês corrente.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function periodo(): array
    {
        $filtros = $this->pageFilters ?? [];

        $inicio = ! empty($filtros['inicio'])
            ? Carbon::parse($filtros['inicio'])->startOfDay()
            : now()->startOfMonth();

        $fim = ! empty($filtros['fim'])
            ? Carbon::parse($filtros['fim'])->endOfDay()
            : now()->endOfDay();

        return [$inicio, $fim];
    }

    protected function brl(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }

    protected function pct(float $valor): string
    {
        return number_format($valor, 1, ',', '.').'%';
    }

    protected function minutos(float $valor): string
    {
        return number_format($valor, 0, ',', '.').' min';
    }
}
