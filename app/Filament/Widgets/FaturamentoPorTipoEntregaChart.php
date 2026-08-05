<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use App\Models\OpcoesEntregas;
use App\Models\Venda;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class FaturamentoPorTipoEntregaChart extends ChartWidget
{
    use HasWidgetShield;
    use InteractsComPeriodo;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Faturamento por tipo de entrega';

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        [$inicio, $fim] = $this->periodo();
        $tipos = $this->tiposEntregaSelecionados();

        // Uma venda pode ter mais de um pedido (ex.: mesa com vários lançamentos);
        // nos raros casos com tipos de entrega diferentes entre eles, usa o pedido
        // mais recente para não contar o mesmo faturamento em mais de uma barra. Se
        // há filtro de tipo de entrega ativo, prioriza o pedido mais recente que
        // bate no filtro — senão a venda pode cair numa barra que ela não deveria.
        $vendas = $this->vendasFiltradasQuery($inicio, $fim)
            ->with(['pedidos' => fn ($q) => $q->select('id', 'pedido_venda_id', 'pedido_opcaoentrega_id')->latest('id')])
            ->get(['id', 'venda_valor_total']);

        $nomes = OpcoesEntregas::pluck('opcaoentrega_nome', 'id');

        $porTipo = $vendas
            ->groupBy(function (Venda $venda) use ($tipos) {
                $pedido = $tipos !== []
                    ? $venda->pedidos->first(fn ($p) => in_array($p->pedido_opcaoentrega_id, $tipos, true))
                    : $venda->pedidos->first();

                return $pedido?->pedido_opcaoentrega_id ?? 'sem_tipo';
            })
            ->map(fn ($grupo) => (float) $grupo->sum('venda_valor_total'))
            ->sortDesc();

        $labels = $porTipo->keys()->map(fn ($id) => $id === 'sem_tipo' ? 'Não informado' : ($nomes[$id] ?? 'Removido'))->all();

        $paleta = ['#3b82f6', '#22c55e', '#f59e0b', '#8b5cf6', '#ef4444', '#14b8a6'];

        return [
            'datasets' => [
                [
                    'label' => 'Faturamento (R$)',
                    'data' => $porTipo->values()->all(),
                    'backgroundColor' => array_slice($paleta, 0, max(1, $porTipo->count())),
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'y' => ['beginAtZero' => true],
            ],
        ];
    }
}
