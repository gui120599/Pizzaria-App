<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComFiltrosProducao;
use App\Services\Cozinha\RelatorioProducaoService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Cards do RelatorioProducao: tempos médios, % no prazo, ritmo, refações e cobertura de fichas. */
class ProducaoStatsOverview extends BaseWidget
{
    use InteractsComFiltrosProducao;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Produção no período';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        [$inicio, $fim] = $this->periodo();
        $servico = app(RelatorioProducaoService::class);
        $resumo = $servico->resumo($inicio, $fim, $this->filtrosProducao());
        $cobertura = $servico->coberturaFichas($inicio, $fim);

        return [
            Stat::make('Tempo total médio', $this->duracao($resumo['total_seg']))
                ->description('Da fila até o pronto · '.$resumo['concluidos'].' item(ns)')
                ->descriptionIcon('heroicon-m-clock')
                ->color('primary'),
            Stat::make('Na fila', $this->duracao($resumo['fila_seg']))
                ->description('Esperando alguém iniciar')
                ->descriptionIcon('heroicon-m-queue-list')
                ->color('gray'),
            Stat::make('Em preparo', $this->duracao($resumo['preparo_seg']))
                ->description('Do iniciar ao pronto')
                ->descriptionIcon('heroicon-m-fire')
                ->color('warning'),
            Stat::make('No prazo', $resumo['no_prazo_pct'] === null ? '—' : $this->pct($resumo['no_prazo_pct']))
                ->description('Dentro do alvo do setor ou do tempo previsto')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color(match (true) {
                    $resumo['no_prazo_pct'] === null => 'gray',
                    $resumo['no_prazo_pct'] >= 85 => 'success',
                    $resumo['no_prazo_pct'] >= 70 => 'warning',
                    default => 'danger',
                }),
            Stat::make('Itens por hora', $resumo['itens_hora'] === null ? '—' : number_format($resumo['itens_hora'], 1, ',', '.'))
                ->description('Nas horas com produção')
                ->descriptionIcon('heroicon-m-bolt')
                ->color('gray'),
            Stat::make('Refações', (string) $resumo['refeitos'])
                ->description('Itens refeitos no período')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color($resumo['refeitos'] > 0 ? 'danger' : 'gray'),
            Stat::make('Cobertura de fichas', $cobertura['percentual'] === null ? '—' : $this->pct($cobertura['percentual']))
                ->description($cobertura['sem_ficha']->count().' produto(s) vendido(s) sem ficha')
                ->descriptionIcon('heroicon-m-book-open')
                ->color(($cobertura['percentual'] ?? 0) >= 90 ? 'success' : 'warning'),
        ];
    }
}
