<?php

namespace App\Console\Commands;

use App\Models\Lancamento;
use App\Models\PagamentosVenda;
use Illuminate\Console\Command;

/**
 * Lista (sem alterar nada) as vendas fiado que ficaram com pagamentos vindos
 * das formas combinadas do pedido e não confirmados: esse dinheiro nunca
 * entrou no caixa, mas a sessão o contou.
 */
class DiagnosticarFiadoComPagamentoDePedido extends Command
{
    protected $signature = 'vendas:diagnosticar-fiado-pagamentos {--csv= : Grava o resultado neste arquivo CSV}';

    protected $description = 'Lista vendas fiado com pagamentos do pedido não confirmados (somente leitura)';

    public function handle(): int
    {
        $vendaIds = Lancamento::receber()->whereNotNull('venda_id')->pluck('venda_id');

        $linhas = PagamentosVenda::whereIn('pg_venda_venda_id', $vendaIds)
            ->whereHas('pagamentoPedido', fn ($query) => $query->where('pg_pedido_pago', false))
            ->with(['venda', 'opcaoPagamento', 'pagamentoPedido'])
            ->orderBy('pg_venda_venda_id')
            ->orderBy('id')
            ->get()
            ->map(fn (PagamentosVenda $pagamento): array => [
                'venda' => $pagamento->pg_venda_venda_id,
                'sessao_caixa' => $pagamento->venda?->venda_sessao_caixa_id ?? '—',
                'pedido' => $pagamento->pagamentoPedido->pg_pedido_pedido_id,
                'forma' => $pagamento->opcaoPagamento?->opcaopag_nome ?? '—',
                'valor' => number_format((float) $pagamento->pg_venda_valor_pagamento, 2, '.', ''),
                'total_venda' => number_format((float) $pagamento->venda?->venda_valor_total, 2, '.', ''),
            ]);

        if ($linhas->isEmpty()) {
            $this->info('Nenhuma venda fiado com pagamento de pedido não confirmado.');

            return self::SUCCESS;
        }

        $this->table(['Venda', 'Sessão', 'Pedido', 'Forma', 'Valor', 'Total da venda'], $linhas->all());

        $this->newLine();
        $this->line('Valor a mais por sessão de caixa:');
        $this->table(
            ['Sessão', 'Pagamentos', 'Valor'],
            $linhas->groupBy('sessao_caixa')->map(fn ($grupo, $sessao): array => [$sessao, $grupo->count(), number_format($grupo->sum(fn (array $linha): float => (float) $linha['valor']), 2, ',', '.')])->values()->all(),
        );

        if ($arquivo = $this->option('csv')) {
            $csv = fopen($arquivo, 'w');
            fputcsv($csv, array_keys($linhas->first()));
            $linhas->each(fn (array $linha) => fputcsv($csv, $linha));
            fclose($csv);
            $this->info("CSV gravado em {$arquivo}");
        }

        return self::SUCCESS;
    }
}
