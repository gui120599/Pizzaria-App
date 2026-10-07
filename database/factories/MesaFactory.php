<?php

namespace Database\Factories;

use App\Models\Mesa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mesa>
 */
class MesaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mesa_nome' => 'Mesa '.fake()->unique()->numberBetween(1, 999),
            'mesa_status' => 'LIBERADA',
            'mesa_tipo' => 'MESA',
        ];
    }
}
