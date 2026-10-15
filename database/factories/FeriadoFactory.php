<?php

namespace Database\Factories;

use App\Enums\Ponto\AbrangenciaFeriado;
use App\Models\Feriado;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feriado>
 */
class FeriadoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'data' => now()->toDateString(),
            'nome' => 'Feriado '.fake()->unique()->word(),
            'abrangencia' => AbrangenciaFeriado::Nacional,
            'recorrente_anual' => false,
        ];
    }

    public function recorrente(): static
    {
        return $this->state(['recorrente_anual' => true]);
    }

    public function facultativo(): static
    {
        return $this->state(['abrangencia' => AbrangenciaFeriado::Facultativo]);
    }
}
