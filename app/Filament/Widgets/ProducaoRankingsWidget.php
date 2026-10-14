<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComFiltrosProducao;
use App\Services\Cozinha\RelatorioProducaoService;
use App\Support\FormatoQuantidade;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Tabelas do RelatorioProducao: produtos mais lentos, operadores, refações
 * por motivo e cobertura de fichas (vendidos sem ficha).
 */
class ProducaoRankingsWidget extends Widget
{
    use InteractsComFiltrosProducao;
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.producao-rankings';

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        [$inicio, $fim] = $this->periodo();
        $filtros = $this->filtrosProducao();
        $servico = app(RelatorioProducaoService::class);

        return [
            'lentos' => $servico->produtosMaisLentos($inicio, $fim, $filtros)
                ->map(fn (object $l) => [$l->produto, $l->categoria ?? '—', $l->itens, $this->duracao((float) $l->total_seg), $this->duracao($l->preparo_seg === null ? null : (float) $l->preparo_seg)]),
            'operadores' => $servico->operadores($inicio, $fim, $filtros)
                ->map(fn (object $l) => [$l->operador, $l->itens, $this->duracao($l->preparo_seg === null ? null : (float) $l->preparo_seg), $this->duracao((float) $l->total_seg)]),
            'refacoes' => $servico->refacoesPorMotivo($inicio, $fim, $filtros)
                ->map(fn (object $l) => [$l->rotulo, $l->total]),
            'semFicha' => $servico->coberturaFichas($inicio, $fim)['sem_ficha']
                ->take(15)
                ->map(fn (object $l) => [$l->produto, $l->categoria ?? '—', FormatoQuantidade::item($l->quantidade)]),
        ];
    }
}
