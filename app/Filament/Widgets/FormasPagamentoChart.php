<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use App\Models\PagamentosVenda;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class FormasPagamentoChart extends ChartWidget
{
    use InteractsComPeriodo;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Formas de pagamento';

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        [$inicio, $fim] = $this->periodo();

        $rows = PagamentosVenda::query()
            ->join('vendas', 'vendas.id', '=', 'pagamentos_vendas.pg_venda_venda_id')
            ->leftJoin('opcoes_pagamentos', 'opcoes_pagamentos.id', '=', 'pagamentos_vendas.pg_venda_opcaopagamento_id')
            ->where('vendas.venda_status', 'FINALIZADA')
            ->whereBetween('vendas.venda_datahora_finalizada', [$inicio, $fim])
            ->selectRaw('COALESCE(opcoes_pagamentos.opcaopag_nome, "Outros") as forma, SUM(pagamentos_vendas.pg_venda_valor_pagamento) as total')
            ->groupBy('forma')
            ->orderByDesc('total')
            ->get();

        $paleta = ['#22c55e', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#ec4899', '#64748b'];

        return [
            'datasets' => [
                [
                    'label' => 'Recebido (R$)',
                    'data' => $rows->pluck('total')->map(fn ($v) => (float) $v)->all(),
                    'backgroundColor' => array_slice($paleta, 0, max(1, $rows->count())),
                ],
            ],
            'labels' => $rows->pluck('forma')->all(),
        ];
    }
}
