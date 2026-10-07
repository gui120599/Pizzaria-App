<?php

namespace Database\Factories;

use App\Enums\StatusChamadoMesaEnum;
use App\Enums\TipoChamadoMesaEnum;
use App\Models\Mesa;
use App\Models\MesaChamado;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MesaChamado>
 */
class MesaChamadoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mc_mesa_id' => Mesa::factory(),
            'mc_tipo' => TipoChamadoMesaEnum::CHAMAR_GARCOM,
            'mc_status' => StatusChamadoMesaEnum::PENDENTE,
        ];
    }

    public function tipo(TipoChamadoMesaEnum $tipo): static
    {
        return $this->state(['mc_tipo' => $tipo]);
    }

    public function atendido(?User $por = null): static
    {
        return $this->state([
            'mc_status' => StatusChamadoMesaEnum::ATENDIDO,
            'mc_atendido_por_id' => $por?->id,
            'mc_atendido_em' => now(),
        ]);
    }
}
