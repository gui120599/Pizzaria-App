<?php

namespace Database\Factories;

use App\Enums\Ponto\SituacaoDia;
use App\Models\Colaborador;
use App\Models\PontoDia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PontoDia>
 */
class PontoDiaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'colaborador_id' => Colaborador::factory(),
            'data_referencia' => now()->toDateString(),
            'situacao' => SituacaoDia::Normal,
            'minutos_previstos' => 360,
            'minutos_trabalhados' => 360,
            'inconsistencias' => [],
            'apurado_em' => now(),
        ];
    }
}
