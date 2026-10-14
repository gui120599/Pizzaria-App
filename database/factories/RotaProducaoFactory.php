<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Produto;
use App\Models\RotaProducao;
use App\Models\SetorProducao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sem estado explícito, a rota é de uma categoria nova.
 *
 * @extends Factory<RotaProducao>
 */
class RotaProducaoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'produto_id' => null,
            'categoria_id' => fn (array $atributos) => $atributos['produto_id'] === null
                ? Categoria::create(['categoria_nome' => 'Categoria '.fake()->unique()->numerify('####')])->id
                : null,
            'setor_producao_id' => SetorProducao::factory(),
            'etapa' => 1,
            'tempo_previsto_min' => 15,
            'ativo' => true,
        ];
    }

    public function doProduto(Produto $produto): static
    {
        return $this->state(['produto_id' => $produto->id, 'categoria_id' => null]);
    }

    public function daCategoria(Categoria $categoria): static
    {
        return $this->state(['produto_id' => null, 'categoria_id' => $categoria->id]);
    }

    public function etapa(int $etapa): static
    {
        return $this->state(['etapa' => $etapa]);
    }
}
