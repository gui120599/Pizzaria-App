<?php

namespace App\Console\Commands;

use App\Models\Pedido;
use App\Services\PromocaoRelampagoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ZerarPedidosIniciados extends Command
{
    protected $signature = 'pedidos:zerar-iniciados
                            {--confirmar : Executa sem pedir confirmação}';

    protected $description = 'Cancela todos os pedidos com status INICIADO';

    public function handle(): int
    {
        $total = Pedido::where('pedido_status', 'INICIADO')->count();

        if ($total === 0) {
            $this->info('Nenhum pedido com status INICIADO encontrado.');

            return self::SUCCESS;
        }

        $this->warn("Encontrados {$total} pedido(s) com status INICIADO.");

        if (! $this->option('confirmar') && ! $this->confirm('Deseja cancelar todos esses pedidos?')) {
            $this->line('Operação cancelada.');

            return self::FAILURE;
        }

        $pedidos = Pedido::where('pedido_status', 'INICIADO')->get();

        DB::transaction(function () use ($pedidos) {
            $promocoes = app(PromocaoRelampagoService::class);

            foreach ($pedidos as $pedido) {
                // Devolve ao saldo qualquer promoção relâmpago consumida pelos
                // itens deste pedido antes de cancelá-lo.
                $promocoes->estornarPedido($pedido);

                $pedido->update([
                    'pedido_status' => 'CANCELADO',
                    'pedido_datahora_cancelado' => now(),
                ]);
            }
        });

        $afetados = $pedidos->count();

        $this->info("{$afetados} pedido(s) cancelado(s) com sucesso.");

        return self::SUCCESS;
    }
}
