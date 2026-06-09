<?php

namespace App\Console\Commands;

use App\Models\Pedido;
use Illuminate\Console\Command;

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

        $afetados = Pedido::where('pedido_status', 'INICIADO')
            ->update([
                'pedido_status'             => 'CANCELADO',
                'pedido_datahora_cancelado' => now(),
            ]);

        $this->info("{$afetados} pedido(s) cancelado(s) com sucesso.");

        return self::SUCCESS;
    }
}
