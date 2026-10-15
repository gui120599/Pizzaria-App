<?php

namespace Database\Seeders;

use App\Enums\Comportamento;
use App\Enums\Periodicidade;
use App\Models\PlanoDespesa;
use Illuminate\Database\Seeder;

/**
 * Contas do grupo "Pessoal" usadas pelos repasses do ponto que ainda não
 * existem no plano padrão. "Salários + encargos" (CLT) já vem do
 * PlanoDespesaSeeder. Idempotente. Depois de rodar, informe os IDs no .env
 * (PONTO_PLANO_CLT, PONTO_PLANO_DIARISTA, PONTO_PLANO_ENTREGADOR).
 *
 * php artisan db:seed --class=PlanosDespesaPontoSeeder
 */
class PlanosDespesaPontoSeeder extends Seeder
{
    public function run(): void
    {
        $pessoal = PlanoDespesa::withTrashed()->firstOrCreate(
            ['nome' => 'Pessoal', 'pai_id' => null],
            ['comportamento' => Comportamento::Fixo, 'periodicidade' => Periodicidade::Mensal],
        );

        foreach (['Diárias e extras', 'Entregas / Motoboys'] as $nome) {
            PlanoDespesa::withTrashed()->firstOrCreate(
                ['nome' => $nome, 'pai_id' => $pessoal->id],
                ['comportamento' => Comportamento::Variavel, 'periodicidade' => Periodicidade::Mensal],
            );
        }
    }
}
