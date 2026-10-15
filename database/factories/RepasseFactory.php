<?php

namespace Database\Factories;

use App\Enums\Ponto\StatusRepasse;
use App\Models\Colaborador;
use App\Models\PontoPeriodo;
use App\Models\Repasse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repasse>
 */
class RepasseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ponto_periodo_id' => PontoPeriodo::factory(),
            'colaborador_id' => Colaborador::factory(),
            'total_proventos' => 0,
            'total_descontos' => 0,
            'valor_liquido' => 0,
            'status' => StatusRepasse::Rascunho,
        ];
    }

    public function status(StatusRepasse $status): static
    {
        return $this->state(['status' => $status]);
    }
}
