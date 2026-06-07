<?php

namespace App\Console\Commands;

use App\Models\Produto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopularQtdVendasProdutos extends Command
{
    protected $signature = 'produtos:popular-qtd-vendas
                            {--meses=3 : Número de meses retroativos a considerar}
                            {--reset : Zerar o contador antes de popular}';

    protected $description = 'Popula produto_qtd_vendas com o histórico de itens_pedidos e itens_vendas';

    public function handle(): int
    {
        $meses = (int) $this->option('meses');
        $desde = now()->subMonths($meses)->startOfDay();

        $this->info("Calculando vendas desde {$desde->format('d/m/Y')} ({$meses} meses)...");

        if ($this->option('reset')) {
            DB::table('produtos')->update(['produto_qtd_vendas' => 0]);
            $this->line('  → Contadores zerados.');
        }

        // Soma de itens_pedidos no período
        $totalPedidos = DB::table('itens_pedidos')
            ->select('item_pedido_produto_id as produto_id', DB::raw('SUM(item_pedido_quantidade) as total'))
            ->where('created_at', '>=', $desde)
            ->groupBy('item_pedido_produto_id')
            ->get()
            ->keyBy('produto_id');

        // Soma de itens_vendas no período
        $totalVendas = DB::table('itens_vendas')
            ->select('item_venda_produto_id as produto_id', DB::raw('SUM(item_venda_quantidade) as total'))
            ->where('created_at', '>=', $desde)
            ->groupBy('item_venda_produto_id')
            ->get()
            ->keyBy('produto_id');

        // Mescla os dois conjuntos por produto_id
        $produtoIds = $totalPedidos->keys()->merge($totalVendas->keys())->unique();

        if ($produtoIds->isEmpty()) {
            $this->warn('Nenhum item encontrado no período informado.');
            return self::SUCCESS;
        }

        $this->withProgressBar($produtoIds, function ($produtoId) use ($totalPedidos, $totalVendas) {
            $qtd = (int) ($totalPedidos->get($produtoId)?->total ?? 0)
                 + (int) ($totalVendas->get($produtoId)?->total ?? 0);

            Produto::where('id', $produtoId)->increment('produto_qtd_vendas', $qtd);
        });

        $this->newLine();
        $this->info("✓ {$produtoIds->count()} produto(s) atualizados.");

        return self::SUCCESS;
    }
}
