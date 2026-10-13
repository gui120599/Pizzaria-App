<?php

namespace App\Filament\Widgets;

use App\Enums\Checklist\ExecucaoStatus;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistNaoConformidade;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ChecklistConformidadeStatsOverview extends StatsOverviewWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 1;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $hoje = ChecklistExecucao::dataOperacional();
        $media7 = $this->mediaConformidade(7);
        $media30 = $this->mediaConformidade(30);

        $naoRealizadosNoMes = ChecklistExecucao::where('status', ExecucaoStatus::NaoRealizado)
            ->whereDate('data_referencia', '>=', $hoje->startOfMonth()->format('Y-m-d'))
            ->count();

        $ncsAbertas = ChecklistNaoConformidade::pendentes()->count();
        $ncsVencidas = ChecklistNaoConformidade::vencidas()->count();

        return [
            Stat::make('Conformidade — 7 dias', $this->percentual($media7))
                ->description('Média dos checklists finalizados')
                ->color($this->cor($media7)),
            Stat::make('Conformidade — 30 dias', $this->percentual($media30))
                ->description('Média dos checklists finalizados')
                ->color($this->cor($media30)),
            Stat::make('Não realizados no mês', (string) $naoRealizadosNoMes)
                ->description('Passaram do horário sem finalizar')
                ->color($naoRealizadosNoMes > 0 ? 'danger' : 'success'),
            Stat::make('Não conformidades abertas', (string) $ncsAbertas)
                ->description($ncsVencidas > 0 ? "{$ncsVencidas} com prazo vencido" : 'Nenhuma vencida')
                ->color($ncsVencidas > 0 ? 'danger' : ($ncsAbertas > 0 ? 'warning' : 'success')),
        ];
    }

    private function mediaConformidade(int $dias): ?float
    {
        $media = ChecklistExecucao::whereNotNull('concluido_em')
            ->whereNotNull('percentual_conformidade')
            ->whereDate('data_referencia', '>=', ChecklistExecucao::dataOperacional()->subDays($dias - 1)->format('Y-m-d'))
            ->avg('percentual_conformidade');

        return $media === null ? null : (float) $media;
    }

    private function percentual(?float $valor): string
    {
        return $valor === null ? '—' : number_format($valor, 1, ',', '.').'%';
    }

    private function cor(?float $valor): string
    {
        return match (true) {
            $valor === null => 'gray',
            $valor >= 95 => 'success',
            $valor >= 80 => 'warning',
            default => 'danger',
        };
    }
}
