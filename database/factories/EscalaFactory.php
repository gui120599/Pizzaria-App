<?php

namespace Database\Factories;

use App\Enums\Ponto\TipoEscala;
use App\Models\Escala;
use App\Models\EscalaDia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Escala>
 */
class EscalaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nome' => 'Escala '.fake()->unique()->words(2, true),
            'tipo' => TipoEscala::Fixa,
            'tolerancia_marcacao_min' => 5,
            'tolerancia_diaria_min' => 10,
            'intervalo_minimo_min' => 60,
        ];
    }

    public function livre(): static
    {
        return $this->state(['tipo' => TipoEscala::Livre]);
    }

    /**
     * Cria os 7 dias: os informados trabalham no horário, os demais são folga.
     *
     * @param  array<int, int>  $diasTrabalhados  ISO (1=seg … 7=dom)
     */
    public function comDias(array $diasTrabalhados, string $entrada, string $saida, int $intervaloMinutos = 0): static
    {
        return $this->afterCreating(function (Escala $escala) use ($diasTrabalhados, $entrada, $saida, $intervaloMinutos): void {
            foreach (range(1, 7) as $dia) {
                $trabalha = in_array($dia, $diasTrabalhados, true);

                EscalaDia::factory()->for($escala)->create([
                    'dia_semana' => $dia,
                    'folga' => ! $trabalha,
                    'entrada' => $trabalha ? $entrada : null,
                    'saida' => $trabalha ? $saida : null,
                    'intervalo_minutos' => $trabalha ? $intervaloMinutos : 0,
                ]);
            }
        });
    }

    /** "Noite pizzaria": qua–dom 17:30–00:30, 1h de intervalo. */
    public function noitePizzaria(): static
    {
        return $this->state(['nome' => 'Noite pizzaria '.fake()->unique()->numberBetween(1, 99999)])
            ->comDias([3, 4, 5, 6, 7], '17:30', '00:30', 60);
    }
}
