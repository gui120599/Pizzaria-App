<?php

namespace App\Console\Commands;

use App\Models\Colaborador;
use App\Services\Ponto\ApuracaoService;
use App\Support\DiaOperacional;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Reapura um dia operacional de todos os colaboradores ativos (padrão: o dia anterior). */
class PontoApurar extends Command
{
    protected $signature = 'ponto:apurar {--data= : Dia operacional (Y-m-d); padrão: ontem}';

    protected $description = 'Reapura o ponto de um dia operacional de todos os colaboradores ativos';

    public function handle(ApuracaoService $apuracao): int
    {
        $dia = $this->option('data')
            ? Carbon::parse((string) $this->option('data'))->startOfDay()
            : DiaOperacional::de(now())->subDay();

        $apurados = 0;

        foreach (Colaborador::ativos($dia)->get() as $colaborador) {
            if ($apuracao->apurarDia($colaborador, $dia) !== null) {
                $apurados++;
            }
        }

        $this->info("{$apurados} dia(s) apurado(s) em {$dia->format('d/m/Y')}.");

        return self::SUCCESS;
    }
}
