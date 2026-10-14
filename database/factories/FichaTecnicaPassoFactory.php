<?php

namespace Database\Factories;

use App\Models\FichaTecnica;
use App\Models\FichaTecnicaPasso;
use App\Models\SetorProducao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FichaTecnicaPasso>
 */
class FichaTecnicaPassoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ficha_tecnica_id' => FichaTecnica::factory(),
            'ordem' => 1,
            'titulo' => fake()->sentence(3),
            'descricao' => null,
            'foto' => null,
            'setor_producao_id' => null,
            'tempo_segundos' => null,
            'temperatura_alvo' => null,
            'ponto_critico' => false,
        ];
    }

    public function doSetor(SetorProducao $setor): static
    {
        return $this->state(['setor_producao_id' => $setor->id]);
    }

    public function cronometrado(int $segundos): static
    {
        return $this->state(['tempo_segundos' => $segundos]);
    }

    public function critico(): static
    {
        return $this->state(['ponto_critico' => true]);
    }
}
