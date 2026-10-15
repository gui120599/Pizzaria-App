<?php

namespace App\Console\Commands;

use App\Services\Ponto\PeriodosPonto;
use Illuminate\Console\Command;

/** Garante o período atual e o próximo de cada periodicidade em uso. Idempotente. */
class PontoAbrirPeriodos extends Command
{
    protected $signature = 'ponto:abrir-periodos';

    protected $description = 'Cria os próximos períodos de apuração do ponto';

    public function handle(PeriodosPonto $periodos): int
    {
        $criados = $periodos->abrirPeriodos(now());

        $this->info("{$criados->count()} período(s) criado(s).");

        return self::SUCCESS;
    }
}
