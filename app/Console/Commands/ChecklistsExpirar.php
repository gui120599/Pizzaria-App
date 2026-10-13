<?php

namespace App\Console\Commands;

use App\Services\Checklist\ChecklistExecucaoService;
use Illuminate\Console\Command;

/** Marca como "não realizado" as execuções que passaram do fim da janela + tolerância. */
class ChecklistsExpirar extends Command
{
    protected $signature = 'checklists:expirar';

    protected $description = 'Expira as execuções de checklist fora da janela de horário';

    public function handle(ChecklistExecucaoService $service): int
    {
        $expiradas = $service->expirarForaDaJanela(now());

        $this->info("{$expiradas} execução(ões) marcada(s) como não realizada(s).");

        return self::SUCCESS;
    }
}
