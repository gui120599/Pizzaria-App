<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComPeriodo;
use App\Models\ItensPedido;
use App\Models\Pedido;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Carbon\Carbon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class PedidosStatsOverview extends BaseWidget
{
    use HasWidgetShield;
    use InteractsComPeriodo;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Operação do período';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        [$inicio, $fim] = $this->periodo();
        [$inicioAnterior, $fimAnterior] = $this->periodoAnterior();

        $atual = $this->indicadores($inicio, $fim);
        $anterior = $this->indicadores($inicioAnterior, $fimAnterior);

        return [
            Stat::make('Pedidos', (string) $atual['pedidos'])
                ->description($this->descricaoComComparativo("{$atual['finalizados']} finalizados", $atual['pedidos'], $anterior['pedidos']))
                ->descriptionIcon('heroicon-m-clipboard-document-list')
                ->chart($this->sparklinePedidos($inicio, $fim))
                ->color('success'),

            Stat::make('Tempo de preparo', $this->minutos($atual['tempo_preparo']))
                ->description($this->descricaoComComparativo('Preparo → pronto (conforme registro no painel)', $atual['tempo_preparo'], $anterior['tempo_preparo']))
                ->descriptionIcon('heroicon-m-fire')
                ->color($atual['tempo_preparo'] <= 20 ? 'success' : ($atual['tempo_preparo'] <= 30 ? 'warning' : 'danger')),

            Stat::make('Tempo de entrega', $atual['tempo_entrega'] > 0 ? $this->minutos($atual['tempo_entrega']) : '—')
                ->description($this->descricaoComComparativo('Saída → entrega (delivery)', $atual['tempo_entrega'], $anterior['tempo_entrega']))
                ->descriptionIcon('heroicon-m-truck')
                ->color($atual['tempo_entrega'] == 0 ? 'gray' : ($atual['tempo_entrega'] <= 40 ? 'success' : ($atual['tempo_entrega'] <= 60 ? 'warning' : 'danger'))),

            Stat::make('Tempo até a entrega', $atual['tempo_ate_entrega'] > 0 ? $this->minutos($atual['tempo_ate_entrega']) : '—')
                ->description($this->descricaoComComparativo('Abertura → entregue (conforme registro)', $atual['tempo_ate_entrega'], $anterior['tempo_ate_entrega']))
                ->descriptionIcon('heroicon-m-clock')
                ->color('info'),

            Stat::make('Itens por pedido', number_format($atual['itens_por_pedido'], 1, ',', '.'))
                ->description($this->descricaoComComparativo("{$atual['itens']} itens no período", $atual['itens_por_pedido'], $anterior['itens_por_pedido']))
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('info'),

            Stat::make('Cancelamentos', (string) $atual['cancelados'])
                ->description($this->descricaoComComparativo("{$this->pct($atual['taxa_cancelamento'])} dos pedidos", $atual['cancelados'], $anterior['cancelados']))
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($atual['taxa_cancelamento'] >= 10 ? 'danger' : ($atual['taxa_cancelamento'] >= 5 ? 'warning' : 'gray')),
        ];
    }

    /**
     * Indicadores de um período, com os filtros do painel. O período atual e o
     * anterior passam por aqui, então a comparação usa a mesma regra.
     *
     * @return array{pedidos: int, finalizados: int, cancelados: int, taxa_cancelamento: float, tempo_preparo: float, tempo_entrega: float, tempo_ate_entrega: float, itens: int, itens_por_pedido: float}
     */
    private function indicadores(Carbon $inicio, Carbon $fim): array
    {
        // Pedidos "reais" = tudo que saiu de INICIADO (carrinho), pela abertura.
        $base = Pedido::query()
            ->tipoAtendimento($this->tiposAtendimentoSelecionados())
            ->where('pedido_status', '!=', 'INICIADO')
            ->whereBetween('pedido_datahora_abertura', [$inicio, $fim]);

        $totalPedidos = (clone $base)->count();
        $finalizados = (clone $base)->where('pedido_status', 'FINALIZADO')->count();
        $cancelados = (clone $base)->where('pedido_status', 'CANCELADO')->count();

        // Tempo médio de preparo: preparo -> pronto (minutos).
        $tPreparo = (float) (clone $base)
            ->whereNotNull('pedido_datahora_preparo')
            ->whereNotNull('pedido_datahora_pronto')
            ->whereColumn('pedido_datahora_pronto', '>=', 'pedido_datahora_preparo')
            ->avg(DB::raw('TIMESTAMPDIFF(SECOND, pedido_datahora_preparo, pedido_datahora_pronto)')) / 60;

        // Tempo médio de entrega: transporte -> entrega (subconjunto delivery).
        $tEntrega = (float) (clone $base)
            ->whereNotNull('pedido_datahora_transporte')
            ->whereNotNull('pedido_datahora_entrega')
            ->whereColumn('pedido_datahora_entrega', '>=', 'pedido_datahora_transporte')
            ->avg(DB::raw('TIMESTAMPDIFF(SECOND, pedido_datahora_transporte, pedido_datahora_entrega)')) / 60;

        // Tempo médio até a entrega: abertura -> entregue. Não usa
        // pedido_datahora_finalizado: ele marca o pagamento, não a entrega.
        $tAteEntrega = (float) (clone $base)
            ->whereNotNull('pedido_datahora_entrega')
            ->whereColumn('pedido_datahora_entrega', '>=', 'pedido_datahora_abertura')
            ->avg(DB::raw('TIMESTAMPDIFF(SECOND, pedido_datahora_abertura, pedido_datahora_entrega)')) / 60;

        // Itens por pedido (média) entre os pedidos do período.
        $totalItens = ItensPedido::query()
            ->join('pedidos', 'pedidos.id', '=', 'itens_pedidos.item_pedido_pedido_id')
            ->whereNull('pedidos.deleted_at')
            ->when($this->tiposAtendimentoSelecionados(), fn ($q, array $tipos) => $q->whereIn('pedidos.id', Pedido::query()->tipoAtendimento($tipos)->select('pedidos.id')))
            ->where('pedidos.pedido_status', '!=', 'INICIADO')
            ->where('itens_pedidos.item_pedido_status', 'INSERIDO')
            ->whereBetween('pedidos.pedido_datahora_abertura', [$inicio, $fim])
            ->count();

        return [
            'pedidos' => $totalPedidos,
            'finalizados' => $finalizados,
            'cancelados' => $cancelados,
            'taxa_cancelamento' => $totalPedidos > 0 ? $cancelados / $totalPedidos * 100 : 0.0,
            'tempo_preparo' => $tPreparo,
            'tempo_entrega' => $tEntrega,
            'tempo_ate_entrega' => $tAteEntrega,
            'itens' => $totalItens,
            'itens_por_pedido' => $totalPedidos > 0 ? $totalItens / $totalPedidos : 0.0,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function sparklinePedidos(Carbon $inicio, Carbon $fim): array
    {
        return Pedido::query()
            ->tipoAtendimento($this->tiposAtendimentoSelecionados())
            ->where('pedido_status', '!=', 'INICIADO')
            ->whereBetween('pedido_datahora_abertura', [$inicio, $fim])
            ->selectRaw('DATE(pedido_datahora_abertura) as dia, COUNT(*) as total')
            ->groupBy('dia')
            ->orderBy('dia')
            ->pluck('total')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
