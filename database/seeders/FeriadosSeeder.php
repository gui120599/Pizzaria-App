<?php

namespace Database\Seeders;

use App\Models\Feriado;
use Illuminate\Database\Seeder;

/**
 * Feriados nacionais do ano atual e do próximo. Idempotente: os fixos são
 * gravados uma vez como recorrentes; os móveis, ano a ano.
 *
 * php artisan db:seed --class=FeriadosSeeder
 */
class FeriadosSeeder extends Seeder
{
    public function run(): void
    {
        $anoAtual = now()->year;

        foreach ([$anoAtual, $anoAtual + 1] as $ano) {
            Feriado::importarNacionais($ano);
        }
    }
}
