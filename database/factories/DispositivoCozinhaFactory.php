<?php

namespace Database\Factories;

use App\Enums\Cozinha\ModoExibicaoCozinha;
use App\Enums\Cozinha\TamanhoFonteCozinha;
use App\Models\DispositivoCozinha;
use App\Models\SetorProducao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DispositivoCozinha>
 */
class DispositivoCozinhaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nome' => 'Tablet '.fake()->unique()->numerify('###'),
            'user_id' => null,
            'setores' => [],
            'modo_exibicao' => ModoExibicaoCozinha::Itens,
            'tamanho_fonte' => TamanhoFonteCozinha::Normal,
            'som_ativo' => true,
            'token_hash' => null,
            'token_expira_em' => null,
            'pareado_em' => null,
            'ultimo_ping_em' => null,
            'ultimo_ip' => null,
            'ativo' => true,
        ];
    }

    public function paraSetores(SetorProducao ...$setores): static
    {
        return $this->state(['setores' => array_map(fn (SetorProducao $setor) => $setor->id, $setores)]);
    }
}
