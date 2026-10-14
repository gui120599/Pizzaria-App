<?php

namespace App\Filament\Widgets;

use App\Models\ProducaoItem;
use App\Services\Cozinha\RelatorioProducaoService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/** Tempo médio de produção de hoje comparado com a média dos últimos 30 dias, por setor. */
class TempoMedioProducaoWidget extends BaseWidget
{
    protected ?string $heading = 'Tempo médio de produção: hoje × 30 dias';

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', ProducaoItem::class);
    }

    protected function getStats(): array
    {
        $servico = app(RelatorioProducaoService::class);
        $hoje = $servico->resumo(now()->startOfDay(), now());
        $media = $servico->resumo(now()->subDays(30)->startOfDay(), now()->subDay()->endOfDay());

        return [
            $this->comparativo('Tempo total', $hoje['total_seg'], $media['total_seg']),
            $this->comparativo('Em preparo', $hoje['preparo_seg'], $media['preparo_seg']),
            Stat::make('No prazo hoje', $hoje['no_prazo_pct'] === null ? '—' : number_format($hoje['no_prazo_pct'], 1, ',', '.').'%')
                ->description('30 dias: '.($media['no_prazo_pct'] === null ? '—' : number_format($media['no_prazo_pct'], 1, ',', '.').'%'))
                ->color('gray'),
        ];
    }

    private function comparativo(string $rotulo, ?float $hoje, ?float $media): Stat
    {
        $stat = Stat::make($rotulo.' hoje', self::minutos($hoje))
            ->description('Média 30 dias: '.self::minutos($media));

        if ($hoje === null || $media === null || $media <= 0) {
            return $stat->color('gray');
        }

        $variacao = ($hoje - $media) / $media * 100;

        return $stat
            ->description(sprintf('%s%s%% vs. 30 dias (%s)', $variacao > 0 ? '+' : '', number_format($variacao, 0, ',', '.'), self::minutos($media)))
            ->descriptionIcon($variacao > 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($variacao > 10 ? 'danger' : ($variacao < -5 ? 'success' : 'gray'));
    }

    private static function minutos(?float $segundos): string
    {
        return $segundos === null ? '—' : number_format($segundos / 60, 1, ',', '.').' min';
    }
}
