<?php

namespace App\Console\Commands;

use App\Services\Compras\FinanceiroCompraService;
use Illuminate\Console\Command;

/**
 * Regra de baixa "automática no vencimento" (débito automático): baixa as
 * parcelas das compras automatizadas que vencem até hoje.
 */
class ComprasBaixarVencimentos extends Command
{
    protected $signature = 'compras:baixar-vencimentos';

    protected $description = 'Dá baixa nas parcelas de fornecedores com débito automático que vencem até hoje';

    public function handle(FinanceiroCompraService $financeiro): int
    {
        $this->info('Parcelas baixadas: '.$financeiro->baixarVencidos());

        return self::SUCCESS;
    }
}
