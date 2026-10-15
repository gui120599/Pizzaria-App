<?php

namespace App\Filament\Widgets;

use App\Enums\Ponto\StatusRepasse;
use App\Models\PontoDia;
use App\Models\Repasse;
use App\Services\Ponto\EspelhoPontoService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Custo de pessoal do mês: proventos dos repasses (por vínculo) e horas extras/noturnas apuradas. */
class CustoPessoalPeriodoWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected ?string $heading = 'Pessoal no mês';

    public static function canView(): bool
    {
        return auth()->user()?->can('view_any:repasse') ?? false;
    }

    protected function getStats(): array
    {
        $inicio = now()->startOfMonth();
        $fim = now()->endOfMonth();

        $repasses = Repasse::query()
            ->with('colaborador')
            ->where('status', '!=', StatusRepasse::Cancelado)
            ->whereHas('periodo', fn ($q) => $q->whereBetween('data_fim', [$inicio->toDateString(), $fim->toDateString()]))
            ->get();

        $porVinculo = $repasses->groupBy(fn (Repasse $repasse): string => $repasse->colaborador->vinculo->getLabel())
            ->map(fn ($grupo): float => (float) $grupo->sum('total_proventos'))
            ->sortDesc();

        $dias = PontoDia::query()->whereBetween('data_referencia', [$inicio->toDateString(), $fim->toDateString()]);

        return [
            Stat::make('Bruto dos repasses', 'R$ '.number_format((float) $repasses->sum('total_proventos'), 2, ',', '.'))
                ->description($porVinculo->map(fn (float $valor, string $vinculo): string => "{$vinculo}: R$ ".number_format($valor, 0, ',', '.'))->take(3)->join(' · ') ?: 'Nenhum repasse no mês'),
            Stat::make('Horas extras', EspelhoPontoService::horas((int) (clone $dias)->sum('minutos_extra_50') + (int) (clone $dias)->sum('minutos_extra_100')))
                ->description('Apuradas no mês (50% + 100%)'),
            Stat::make('Horas noturnas', EspelhoPontoService::horas((int) (clone $dias)->sum('minutos_noturnos')))
                ->description('Relógio, 22h–5h'),
            Stat::make('Faltas', (string) (clone $dias)->where('situacao', 'falta')->count())
                ->description('Dias no mês'),
        ];
    }
}
