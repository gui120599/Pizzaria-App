<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Collection;

class ProdutosVendidosPorPeriodoChart extends ChartWidget
{
    use InteractsComPeriodo;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Produtos vendidos: valor e quantidade';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    public ?string $filter = 'dia';

    protected function getFilters(): ?array
    {
        return [
            'dia' => 'Por dia',
            'mes' => 'Por mês',
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    public function getDescription(): ?string
    {
        $linhas = $this->linhasAgrupadas();
        $unidade = $this->filter === 'mes' ? 'mês' : 'dia';

        if ($linhas->isEmpty()) {
            return "Sem vendas no período (média por {$unidade})";
        }

        $valorMedio = $linhas->avg('valor');
        $qtdMedia = $linhas->avg('qtd');

        return sprintf(
            'Média por %s: %s • %s unidades',
            $unidade,
            $this->brl((float) $valorMedio),
            number_format((float) $qtdMedia, 1, ',', '.'),
        );
    }

    protected function getData(): array
    {
        $linhas = $this->linhasAgrupadas();

        return [
            'datasets' => [
                [
                    'label' => 'Valor (R$)',
                    'data' => $linhas->pluck('valor')->all(),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'yAxisID' => 'y',
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Quantidade (un.)',
                    'data' => $linhas->pluck('qtd')->all(),
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.15)',
                    'yAxisID' => 'y1',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $linhas->pluck('rotulo')->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => true],
            ],
            'scales' => [
                'y' => [
                    'type' => 'linear',
                    'position' => 'left',
                    'beginAtZero' => true,
                ],
                'y1' => [
                    'type' => 'linear',
                    'position' => 'right',
                    'beginAtZero' => true,
                    'grid' => ['drawOnChartArea' => false],
                ],
            ],
        ];
    }

    /**
     * Valor, quantidade e rótulo por dia ou por mês (conforme o filtro do
     * card), já com os filtros globais do painel (tipo de entrega, categoria
     * e produto) aplicados via itensFiltradosQuery().
     *
     * @return Collection<int, object{rotulo: string, valor: float, qtd: float}>
     */
    private function linhasAgrupadas(): Collection
    {
        [$inicio, $fim] = $this->periodo();

        // Restrito às duas opções de getFilters(): nunca interpola valor livre do
        // usuário na query, só decide entre estes dois formatos fixos.
        $formato = $this->filter === 'mes' ? '%Y-%m' : '%Y-%m-%d';

        $rows = $this->itensFiltradosQuery($inicio, $fim)
            ->selectRaw("DATE_FORMAT(vendas.venda_datahora_finalizada, '{$formato}') as bucket")
            ->selectRaw('SUM(itens_vendas.item_venda_valor) as valor')
            ->selectRaw('SUM(itens_vendas.item_venda_quantidade) as qtd')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return $rows->map(fn ($r) => (object) [
            'rotulo' => $this->filter === 'mes'
                ? Carbon::createFromFormat('Y-m', $r->bucket)->translatedFormat('M/Y')
                : Carbon::parse($r->bucket)->format('d/m'),
            'valor' => (float) $r->valor,
            'qtd' => (float) $r->qtd,
        ]);
    }
}
