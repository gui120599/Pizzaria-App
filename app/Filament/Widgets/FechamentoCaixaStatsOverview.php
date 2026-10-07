<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use App\Services\RelatorioFechamentoCaixaService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FechamentoCaixaStatsOverview extends BaseWidget
{
    use HasWidgetShield;
    use InteractsComPeriodo;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Indicadores';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $resumo = (new RelatorioFechamentoCaixaService($this->pageFilters ?? []))->resumo();

        return [
            Stat::make('Faturamento', $this->brl($resumo['faturamento']))
                ->description("{$resumo['vendas']} vendas · ticket médio {$this->brl($resumo['ticket_medio'])}")
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('primary'),

            Stat::make('Receita operacional', $this->brl($resumo['receita_operacional']))
                ->description("Sem a taxa de serviço ({$this->brl($resumo['taxa_servico'])})")
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('primary'),

            Stat::make('Taxas das maquininhas', $this->brl($resumo['mdr']))
                ->description($resumo['sem_taxa'] > 0
                    ? "{$resumo['sem_taxa']} pagamento(s) sem taxa cadastrada"
                    : "MDR efetivo {$this->pct($resumo['mdr_efetivo'])} sobre {$this->brl($resumo['volume_maquininha'])}")
                ->descriptionIcon($resumo['sem_taxa'] > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-credit-card')
                ->color($resumo['sem_taxa'] > 0 ? 'warning' : 'danger'),

            Stat::make('Imposto da NFC-e', $this->brl($resumo['imposto_nfe']))
                ->description("{$this->pct($resumo['imposto_efetivo'])} sobre {$this->brl($resumo['faturamento_nfe'])} com nota · {$this->brl($resumo['faturamento_sem_nfe'])} sem nota autorizada")
                ->descriptionIcon('heroicon-m-document-text')
                ->color('danger'),

            Stat::make('Margem de contribuição', $this->brl($resumo['margem_contribuicao']))
                ->description("{$this->pct($resumo['margem_percentual'])} da receita operacional")
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color($resumo['margem_contribuicao'] >= 0 ? 'success' : 'danger'),

            Stat::make('CMV', $this->pct($resumo['cmv_percentual']))
                ->description("{$this->brl($resumo['cmv'])} · custo em {$this->pct($resumo['cobertura_custo'])} da receita")
                ->descriptionIcon('heroicon-m-cube')
                ->color(match (true) {
                    $resumo['cmv_percentual'] <= 35 => 'success',
                    $resumo['cmv_percentual'] <= 40 => 'warning',
                    default => 'danger',
                }),

            Stat::make('Recebido', $this->brl($resumo['recebido']))
                ->description("{$this->pct($resumo['participacao_maquininha'])} em maquininha · fiado {$this->brl($resumo['fiado'])}")
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('gray'),

            Stat::make('Cancelamentos', (string) $resumo['canceladas'])
                ->description("{$this->brl($resumo['valor_cancelado'])} · {$this->pct($resumo['taxa_cancelamento'])} das vendas")
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($resumo['canceladas'] > 0 ? 'warning' : 'gray'),
        ];
    }
}
