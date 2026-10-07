<?php

namespace Database\Factories;

use App\Models\MesaParticipante;
use App\Models\SessaoMesa;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MesaParticipante>
 */
class MesaParticipanteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mp_sessao_mesa_id' => SessaoMesa::factory(),
            'mp_nome' => fake()->firstName(),
            'mp_celular' => fake()->numerify('649########'),
            'mp_token_hash' => MesaParticipante::hashDoToken(MesaParticipante::gerarToken()),
        ];
    }

    /** Celular com o token informado (o teste guarda o token em texto para o cookie). */
    public function comToken(string $token): static
    {
        return $this->state(['mp_token_hash' => MesaParticipante::hashDoToken($token)]);
    }

    public function bloqueado(?User $por = null): static
    {
        return $this->state([
            'mp_bloqueado_em' => now(),
            'mp_bloqueado_por_id' => $por?->id,
        ]);
    }
}
