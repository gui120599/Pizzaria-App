<?php

namespace App\Console\Commands;

use App\Services\Ponto\IntegridadePonto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Percorre a cadeia de marcações (NSR + hash encadeado) e aponta qualquer quebra. */
class PontoVerificarIntegridade extends Command
{
    protected $signature = 'ponto:verificar-integridade';

    protected $description = 'Verifica a cadeia de hash das marcações de ponto';

    public function handle(IntegridadePonto $integridade): int
    {
        $problemas = $integridade->verificar();

        if ($problemas === []) {
            $this->info('Cadeia de marcações íntegra.');

            return self::SUCCESS;
        }

        Log::error('Cadeia de marcações de ponto com problemas.', ['problemas' => $problemas]);
        $this->table(['NSR', 'Problema'], array_map(fn (array $p): array => [$p['nsr'] ?? '—', $p['problema']], $problemas));

        return self::FAILURE;
    }
}
