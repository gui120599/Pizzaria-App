<?php

namespace Database\Factories;

use App\Models\Colaborador;
use App\Models\ColaboradorEscala;
use App\Models\Escala;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ColaboradorEscala>
 */
class ColaboradorEscalaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'colaborador_id' => Colaborador::factory(),
            'escala_id' => Escala::factory(),
            'vigente_desde' => now()->subYear()->toDateString(),
            'vigente_ate' => null,
        ];
    }

    public function vigencia(string $desde, ?string $ate = null): static
    {
        return $this->state(['vigente_desde' => $desde, 'vigente_ate' => $ate]);
    }
}
