<?php

namespace Database\Seeders;

use App\Models\NotaMoeda;
use Illuminate\Database\Seeder;

class NotaMoedaSeeder extends Seeder
{
    public function run(): void
    {
        $cedulas = [200, 100, 50, 20, 10, 5, 2];
        $moedas = [1, 0.50, 0.25, 0.10, 0.05];

        $ordem = count($cedulas) + count($moedas);

        foreach ($cedulas as $valor) {
            NotaMoeda::updateOrCreate(
                ['valor' => $valor],
                [
                    'descricao' => 'R$ '.number_format($valor, 2, ',', '.'),
                    'tipo' => 'cedula',
                    'ordem_exibicao' => $ordem--,
                ],
            );
        }

        foreach ($moedas as $valor) {
            NotaMoeda::updateOrCreate(
                ['valor' => $valor],
                [
                    'descricao' => 'R$ '.number_format($valor, 2, ',', '.'),
                    'tipo' => 'moeda',
                    'ordem_exibicao' => $ordem--,
                ],
            );
        }
    }
}
