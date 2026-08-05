<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\FinanceiroDashboard;
use App\Filament\Resources\Produtos\ProdutoResource;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\Venda;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Retrato de "agora" para quem abre o painel pela manhã: como está o
 * faturamento de hoje, o que está em andamento na operação e se há algo
 * pedindo atenção (caixa fechado, estoque zerado). Sem filtro de período —
 * isso já existe no Painel Financeiro/Operacional.
 */
class ResumoOperacionalWidget extends BaseWidget
{
    protected ?string $heading = 'Agora';

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * Cada card só aparece se o usuário também conseguisse ver o módulo que
     * ele resume — mesma lógica de permissão do restante do painel, evitando
     * expor números (principalmente financeiros) pra quem não tem acesso.
     */
    protected function getStats(): array
    {
        $stats = [];

        if (FinanceiroDashboard::canAccess()) {
            $faturamentoHoje = (float) Venda::query()
                ->where('venda_status', 'FINALIZADA')
                ->whereDate('venda_datahora_finalizada', now())
                ->sum('venda_valor_total');

            $vendasHoje = Venda::query()
                ->where('venda_status', 'FINALIZADA')
                ->whereDate('venda_datahora_finalizada', now())
                ->count();

            $stats[] = Stat::make('Faturamento de hoje', $this->brl($faturamentoHoje))
                ->description("{$vendasHoje} vendas finalizadas hoje")
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success');
        }

        if (auth()->user()?->can('view_any:pedido')) {
            $pedidosAtivos = Pedido::query()
                ->whereIn('pedido_status', ['ABERTO', 'PREPARANDO', 'PRONTO', 'EM TRANSPORTE', 'ENTREGUE'])
                ->count();

            $stats[] = Stat::make('Pedidos em andamento', (string) $pedidosAtivos)
                ->description('Ainda não finalizados ou cancelados')
                ->descriptionIcon('heroicon-m-clock')
                ->color($pedidosAtivos > 0 ? 'info' : 'gray');
        }

        if (auth()->user()?->can('abrir:sessao_caixa') || auth()->user()?->can('fechar:sessao_caixa')) {
            $caixasAbertos = SessaoCaixa::query()->where('sessaocaixa_status', 'ABERTA');
            $numCaixas = (clone $caixasAbertos)->count();
            $saldoCaixas = (float) (clone $caixasAbertos)->sum('sessaocaixa_saldo_final');

            $stats[] = Stat::make('Caixa', $numCaixas > 0 ? "{$numCaixas} sessão(ões) aberta(s)" : 'Nenhum caixa aberto')
                ->description($numCaixas > 0 ? 'Saldo somado: '.$this->brl($saldoCaixas) : 'Abra uma sessão para operar')
                ->descriptionIcon('heroicon-m-calculator')
                ->color($numCaixas > 0 ? 'success' : 'warning');
        }

        if (ProdutoResource::canAccess()) {
            $produtosSemEstoque = Produto::query()
                ->where('produto_controla_estoque', true)
                ->where('produto_saldo_estoque', '<=', 0)
                ->count();

            $stats[] = Stat::make('Produtos sem estoque', (string) $produtosSemEstoque)
                ->description($produtosSemEstoque > 0 ? 'Precisam de reposição' : 'Tudo certo')
                ->descriptionIcon('heroicon-m-archive-box-x-mark')
                ->color($produtosSemEstoque > 0 ? 'danger' : 'gray');
        }

        return $stats;
    }

    private function brl(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }
}
