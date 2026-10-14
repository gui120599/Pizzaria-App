<?php

namespace App\Console\Commands;

use App\Models\Pedido;
use App\Services\Cozinha\RoteadorProducao;
use Illuminate\Console\Command;

/** Suporte: regera os itens de produção que faltarem num pedido (idempotente). */
class CozinhaReconstruir extends Command
{
    protected $signature = 'cozinha:reconstruir {pedido : ID do pedido}';

    protected $description = 'Regera os itens de produção faltantes de um pedido';

    public function handle(RoteadorProducao $roteador): int
    {
        $pedido = Pedido::find($this->argument('pedido'));

        if ($pedido === null) {
            $this->error('Pedido não encontrado.');

            return self::FAILURE;
        }

        $itens = $roteador->gerarParaPedido($pedido);

        $this->info("Pedido #{$pedido->id}: {$itens->count()} item(ns) de produção.");

        return self::SUCCESS;
    }
}
