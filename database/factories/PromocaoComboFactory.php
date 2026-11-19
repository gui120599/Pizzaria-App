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

    /**
     * Combo por grupos: [['Pizza Grande', 1, $categoria], ...].
     *
     * @param  list<array{0: string, 1: int, 2: Categoria}>  $grupos
     */
    public function comGrupos(array $grupos): static
    {
        return $this->state(['promocombo_quantidade' => array_sum(array_column($grupos, 1))])
            ->afterCreating(function (PromocaoCombo $promocao) use ($grupos): void {
                foreach ($grupos as $ordem => [$nome, $quantidade, $categoria]) {
                    $promocao->grupos()->create(['pcg_nome' => $nome, 'pcg_quantidade' => $quantidade, 'pcg_ordem' => $ordem + 1])
                        ->participantes()->create(['pcp_categoria_id' => $categoria->id]);
                }
            });
    }

    /** Participação por categoria (com subcategorias), num grupo único com a quantidade da promoção. */
    public function daCategoria(Categoria $categoria): static
    {
        return $this->afterCreating(fn (PromocaoCombo $promocao) => $promocao->participantes()->create([
            'pcp_categoria_id' => $categoria->id,
        ]));
    }
}
