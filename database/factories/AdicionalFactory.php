<?php

namespace Database\Factories;

use App\Models\Adicional;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Adicional>
 */
class AdicionalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'adicional_nome' => fake()->unique()->words(2, true),
            'adicional_valor' => fake()->randomFloat(2, 1, 15),
        ];
    }
}
