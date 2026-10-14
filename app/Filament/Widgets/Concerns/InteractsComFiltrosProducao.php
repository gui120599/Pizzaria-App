<?php

namespace App\Filament\Widgets\Concerns;

use App\Models\ProducaoItem;
use Illuminate\Support\Facades\Gate;

/**
 * Filtros do RelatorioProducao (período, setor, produto, operador) lidos de
 * $pageFilters pelos widgets da página.
 */
trait InteractsComFiltrosProducao
{
    use InteractsComPeriodo;

    public static function canView(): bool
    {
        return Gate::allows('verRelatorio', ProducaoItem::class);
    }

    /** @return array{setor_id: ?int, produto_id: ?int, operador_id: ?int} */
    protected function filtrosProducao(): array
    {
        $filtros = $this->pageFilters ?? [];

        return [
            'setor_id' => filled($filtros['setor_id'] ?? null) ? (int) $filtros['setor_id'] : null,
            'produto_id' => filled($filtros['produto_id'] ?? null) ? (int) $filtros['produto_id'] : null,
            'operador_id' => filled($filtros['operador_id'] ?? null) ? (int) $filtros['operador_id'] : null,
        ];
    }

    /** 754 s → "12min 34s"; null → "—". */
    protected function duracao(?float $segundos): string
    {
        if ($segundos === null) {
            return '—';
        }

        $segundos = (int) round($segundos);

        return $segundos < 60
            ? "{$segundos}s"
            : sprintf('%dmin %02ds', intdiv($segundos, 60), $segundos % 60);
    }
}
