<?php

namespace App\Console\Commands;

use App\Models\ChecklistExecucao;
use App\Services\Checklist\ChecklistExecucaoService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Cria as execuções pendentes do dia operacional para os modelos ativos
 * agendados. Agendado logo após config('checklists.virada_dia'); idempotente,
 * então rodar de novo (ou manualmente com --data) não duplica nada.
 */
class ChecklistsGerarPendentes extends Command
{
    protected $signature = 'checklists:gerar-pendentes {--data= : Dia operacional (Y-m-d); padrão: hoje}';

    protected $description = 'Gera as execuções pendentes de checklist do dia operacional';

    public function handle(ChecklistExecucaoService $service): int
    {
        $data = $this->option('data');

        if ($data !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            $this->error('Data inválida. Use o formato AAAA-MM-DD.');

            return self::INVALID;
        }

        $dia = $data !== null ? CarbonImmutable::parse($data) : ChecklistExecucao::dataOperacional();

        $criadas = $service->gerarPendentesDoDia($dia);

        $this->info("{$criadas} execução(ões) pendente(s) gerada(s) para {$dia->format('d/m/Y')}.");

        return self::SUCCESS;
    }
}
