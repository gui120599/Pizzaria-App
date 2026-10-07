<?php

namespace Database\Factories;

use App\Models\Pergunta;
use App\Models\PerguntaOpcao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PerguntaOpcao>
 */
class PerguntaOpcaoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pergunta_opcao_pergunta_id' => Pergunta::factory(),
            'pergunta_opcao_nome' => fake()->randomElement(['Catupiry', 'Cheddar', 'Tradicional']),
            'pergunta_opcao_valor' => 0,
            'pergunta_opcao_ordem' => 0,
            'pergunta_opcao_ativa' => true,
        ];
    }
}
