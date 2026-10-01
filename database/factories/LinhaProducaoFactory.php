<?php

namespace Database\Factories;

use App\Models\LinhaProducao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LinhaProducao>
 */
class LinhaProducaoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'linha_nome' => $this->faker->unique()->words(2, true),
        ];
    }
}
