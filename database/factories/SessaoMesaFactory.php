<?php

namespace Database\Factories;

use App\Models\Mesa;
use App\Models\SessaoMesa;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessaoMesa>
 */
class SessaoMesaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sessao_mesa_mesa_id' => Mesa::factory(),
            'sessao_mesa_status' => 'ABERTA',
            'sessao_mesa_pessoas' => 2,
            // Garçom dono da conta. name_first é obrigatório e a UserFactory não o preenche.
            'sessao_mesa_usuario_id' => User::factory()->state(fn () => ['name_first' => fake()->firstName()]),
        ];
    }
}
