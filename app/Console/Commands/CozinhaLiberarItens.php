<?php

namespace App\Console\Commands;

use App\Services\Cozinha\ProducaoService;
use Illuminate\Console\Command;

/** Disparo por tempo de preparo: libera para a fila os itens cujo horário chegou. */
class CozinhaLiberarItens extends Command
{
    protected $signature = 'cozinha:liberar-itens';

    protected $description = 'Libera para a fila os itens de produção segurados pelo disparo por tempo de preparo';

    public function handle(ProducaoService $producao): int
    {
        if (! config('cozinha.ativo') || ! config('cozinha.disparo_por_tempo_preparo')) {
            return self::SUCCESS;
        }

        $liberados = $producao->liberarVencidos(now());

        $this->info("{$liberados} item(ns) liberado(s).");

        return self::SUCCESS;
    }
}
