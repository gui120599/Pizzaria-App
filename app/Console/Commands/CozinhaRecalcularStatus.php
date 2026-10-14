<?php

namespace App\Console\Commands;

use App\Models\Pedido;
use App\Services\Cozinha\SincronizadorPedidoProducao;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Conferência: recalcula o status de produção (cache) dos pedidos com produção. */
class CozinhaRecalcularStatus extends Command
{
    protected $signature = 'cozinha:recalcular-status {--desde= : Data inicial (Y-m-d); padrão: hoje}';

    protected $description = 'Recalcula pedidos.pedido_producao_status a partir dos itens de produção';

    public function handle(SincronizadorPedidoProducao $sincronizador): int
    {
        $desde = Carbon::parse($this->option('desde') ?? today()->toDateString())->startOfDay();
        $total = 0;

        Pedido::whereHas('producaoItens')
            ->where('created_at', '>=', $desde)
            ->chunkById(200, function ($pedidos) use ($sincronizador, &$total): void {
                foreach ($pedidos as $pedido) {
                    $sincronizador->recalcular($pedido);
                    $total++;
                }
            });

        $this->info("{$total} pedido(s) recalculado(s).");

        return self::SUCCESS;
    }
}
