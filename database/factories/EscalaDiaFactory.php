<?php

namespace Database\Factories;

use App\Models\Escala;
use App\Models\EscalaDia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EscalaDia>
 */
class EscalaDiaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'escala_id' => Escala::factory(),
            'dia_semana' => 1,
            'folga' => false,
            'entrada' => '18:00',
            'saida' => '23:00',
            'intervalo_minutos' => 0,
        ];
    }

    public function folga(): static
    {
        return $this->state(['folga' => true, 'entrada' => null, 'saida' => null, 'intervalo_minutos' => 0]);
    }
}
