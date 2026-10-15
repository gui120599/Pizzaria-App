<?php

namespace App\Console\Commands;

use App\Models\PontoMarcacao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Apaga as selfies além da retenção (LGPD). A marcação é imutável e mantém
 * o caminho: o espelho mostra "foto expirada" quando o arquivo não existe.
 */
class PontoLimparSelfies extends Command
{
    protected $signature = 'ponto:limpar-selfies';

    protected $description = 'Remove as selfies de ponto além do prazo de retenção';

    public function handle(): int
    {
        $disco = Storage::disk((string) config('ponto.disco'));
        $limite = now()->subDays((int) config('ponto.retencao_selfie_dias', 90));
        $removidas = 0;

        PontoMarcacao::query()
            ->whereNotNull('foto_caminho')
            ->where('registrada_em', '<', $limite)
            ->lazyById()
            ->each(function (PontoMarcacao $marcacao) use ($disco, &$removidas): void {
                if ($disco->exists($marcacao->foto_caminho)) {
                    $disco->delete($marcacao->foto_caminho);
                    $removidas++;
                }
            });

        $this->info("{$removidas} selfie(s) removida(s).");

        return self::SUCCESS;
    }
}
