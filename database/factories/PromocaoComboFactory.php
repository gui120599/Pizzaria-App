<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\PromocaoCombo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromocaoCombo>
 */
class PromocaoComboFactory extends Factory
{
    /**
     * Promoção vigente agora, "2 por R$ 87,99", sem participantes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promocombo_nome' => '2 Pizzas Grandes por R$ 87,99',
            'promocombo_ativa' => true,
            'promocombo_quantidade' => 2,
            'promocombo_preco' => 87.99,
            'promocombo_recorrente' => false,
            'promocombo_inicio' => now()->subHour(),
            'promocombo_fim' => now()->addHour(),
        ];
    }

    /** Participação por categoria (com subcategorias). */
    public function daCategoria(Categoria $categoria): static
    {
        return $this->afterCreating(fn (PromocaoCombo $promocao) => $promocao->participantes()->create([
            'pcp_categoria_id' => $categoria->id,
        ]));
    }
}
