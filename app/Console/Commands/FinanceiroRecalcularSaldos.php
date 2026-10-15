<?php

namespace App\Console\Commands;

use App\Models\ContaFinanceira;
use App\Services\Financeiro\MovimentacaoService;
use Illuminate\Console\Command;

/** Reconstrói o saldo_atual (cache) de cada conta a partir do saldo inicial e das movimentações, e aponta divergências. */
class FinanceiroRecalcularSaldos extends Command
{
    protected $signature = 'financeiro:recalcular-saldos';

    protected $description = 'Recalcula o saldo das contas financeiras a partir das movimentações';

    public function handle(MovimentacaoService $movimentacoes): int
    {
        $divergentes = [];

        foreach (ContaFinanceira::withTrashed()->ordenadas()->get() as $conta) {
            $saldo = $movimentacoes->recalcularSaldo($conta);

            if (abs($saldo['anterior'] - $saldo['atual']) >= 0.01) {
                $divergentes[] = [$conta->nome, number_format($saldo['anterior'], 2, ',', '.'), number_format($saldo['atual'], 2, ',', '.')];
            }
        }

        if ($divergentes === []) {
            $this->info('Saldos conferidos: nenhuma divergência.');

            return self::SUCCESS;
        }

        $this->warn('Saldos corrigidos:');
        $this->table(['Conta', 'Antes', 'Agora'], $divergentes);

        return self::SUCCESS;
    }
}
