<?php

namespace App\Filament\Widgets;

use App\Enums\Compras\TipoPendenciaCompra;
use App\Enums\Financeiro\StatusConciliacaoExtrato;
use App\Enums\StatusLancamento;
use App\Models\Compra;
use App\Models\CompraPendencia;
use App\Models\ExtratoBancarioItem;
use App\Models\Lancamento;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/** Resumo da automação de compras, no topo da caixa de entrada de compras. */
class ComprasHojeWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('resolver:pendencia_compra') ?? false;
    }

    protected function getStats(): array
    {
        $automatizadas = fn (): Builder => Compra::query()->where('compra_importacao_automatica', true);

        $ultimos30 = $automatizadas()->where('created_at', '>=', now()->subDays(30));
        $total30 = (clone $ultimos30)->count();
        $semIntervencao = (clone $ultimos30)
            ->whereDoesntHave('pendencias', fn (Builder $query): Builder => $query->where('tipo', '!=', TipoPendenciaCompra::PrecoForaPadrao))
            ->count();

        $aPagar = Lancamento::query()
            ->pagar()
            ->whereIn('status', [StatusLancamento::Pendente, StatusLancamento::Parcial])
            ->whereDate('vencimento', '<=', today()->addDays(7))
            ->whereHas('compra', fn (Builder $query): Builder => $query->where('compra_importacao_automatica', true))
            ->with('pagamentos')
            ->get()
            ->sum(fn (Lancamento $titulo): float => (float) $titulo->valorRestante);

        return [
            Stat::make('Notas importadas hoje', $automatizadas()->whereDate('created_at', today())->count()),
            Stat::make('Sem intervenção (30 dias)', $total30 > 0 ? Number::percentage($semIntervencao / $total30 * 100, locale: 'pt_BR') : '—')
                ->description("{$semIntervencao} de {$total30} notas"),
            Stat::make('Pendências abertas', CompraPendencia::query()->emAberto()->count())
                ->color('warning'),
            Stat::make('A pagar em 7 dias', Number::currency($aPagar, 'BRL', 'pt_BR'))
                ->description('Compras automatizadas, inclui vencidos'),
            Stat::make('Boletos conciliados hoje', ExtratoBancarioItem::query()
                ->where('status_conciliacao', StatusConciliacaoExtrato::Conciliado)
                ->whereDate('updated_at', today())
                ->count())
                ->description('Pelo extrato do Banco do Brasil'),
        ];
    }
}
