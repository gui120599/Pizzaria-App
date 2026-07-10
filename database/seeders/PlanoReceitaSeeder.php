<?php

namespace Database\Seeders;

use App\Models\PlanoReceita;
use Illuminate\Database\Seeder;

class PlanoReceitaSeeder extends Seeder
{
    public function run(): void
    {
        // [nome do grupo => [contas...]]
        $estrutura = [
            'Vendas' => [
                'Balcão / Retirada',
                'Delivery Próprio',
                'iFood',
                'Salão (dine-in)',
            ],
            'Outras' => [
                'Outras Receitas',
            ],
        ];

        foreach ($estrutura as $nomeGrupo => $contas) {
            $grupo = PlanoReceita::firstOrCreate(
                ['nome' => $nomeGrupo, 'pai_id' => null],
            );

            foreach ($contas as $nomeConta) {
                PlanoReceita::firstOrCreate(
                    ['nome' => $nomeConta, 'pai_id' => $grupo->id],
                );
            }
        }
    }
}
