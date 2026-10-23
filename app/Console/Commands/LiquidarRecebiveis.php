<?php

namespace App\Console\Commands;

use App\Services\Financeiro\LiquidacaoRecebiveisService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Liquida os recebíveis de maquininha que venceram: entrada do bruto na conta
 * da adquirente e a taxa como despesa (ver LiquidacaoRecebiveisService).
 */
class LiquidarRecebiveis extends Command
{
    protected $signature = 'financeiro:liquidar-recebiveis
        {--ate= : Liquida os vencidos até esta data (padrão: hoje)}';

    protected $description = 'Liquida os recebíveis de adquirente com repasse até hoje';

    public function handle(LiquidacaoRecebiveisService $liquidacao): int
    {
        $resultado = $liquidacao->liquidarVencidos($this->option('ate') ? Carbon::parse($this->option('ate')) : null);

        $this->info("{$resultado['liquidados']} recebível(is) liquidado(s).");

        foreach ($resultado['falhas'] as $id => $mensagem) {
            $this->error("Recebível #{$id}: {$mensagem}");
        }

        return $resultado['falhas'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
