<?php

namespace App\Console\Commands;

use App\Services\Gestao\Acoes\AcoesClaudeService;
use App\Services\Gestao\PausaProdutoService;
use Illuminate\Console\Command;

/**
 * Religa os produtos cuja pausa (acabou o insumo) chegou no horário de volta
 * e marca como expiradas as prévias de ação do Claude não confirmadas.
 */
class ProdutosRetomarPausas extends Command
{
    protected $signature = 'produtos:retomar-pausas';

    protected $description = 'Retoma os produtos pausados cujo horário de volta já chegou';

    public function handle(PausaProdutoService $pausas, AcoesClaudeService $acoes): int
    {
        $retomadas = $pausas->retomarVencidas();
        $expiradas = $acoes->expirarVencidas();

        if ($retomadas > 0 || $expiradas > 0) {
            $this->info("{$retomadas} produto(s) retomado(s); {$expiradas} prévia(s) do Claude expirada(s).");
        }

        return self::SUCCESS;
    }
}
