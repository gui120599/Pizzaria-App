<?php

namespace Database\Factories;

use App\Enums\Cozinha\TipoSetorProducao;
use App\Models\SetorProducao;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SetorProducao>
 */
class SetorProducaoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nome = 'Setor '.fake()->unique()->numerify('####');

        return [
            'nome' => $nome,
            'slug' => Str::slug($nome),
            'tipo' => TipoSetorProducao::Producao,
            'cor' => '#f97316',
            'icone' => null,
            'ordem' => 0,
            'tempo_alerta_min' => 10,
            'tempo_alvo_min' => 15,
            'explode_unidades' => true,
            'permite_pular_inicio' => false,
            'exige_operador' => true,
            'exibir_ficha_automatica' => false,
            'som_novo_item' => true,
            'ativo' => true,
        ];
    }

    /** Bar: 6 latas = 1 card "6×", pode marcar pronto direto. */
    public function bar(): static
    {
        return $this->state([
            'explode_unidades' => false,
            'permite_pular_inicio' => true,
            'tempo_alerta_min' => 3,
            'tempo_alvo_min' => 5,
        ]);
    }

    public function expedicao(): static
    {
        return $this->state(['tipo' => TipoSetorProducao::Expedicao]);
    }

    public function semOperador(): static
    {
        return $this->state(['exige_operador' => false]);
    }

    public function inativo(): static
    {
        return $this->state(['ativo' => false]);
    }
}
