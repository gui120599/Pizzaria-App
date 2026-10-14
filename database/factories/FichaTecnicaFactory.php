<?php

namespace Database\Factories;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\FichaTecnica;
use App\Models\Produto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FichaTecnica>
 */
class FichaTecnicaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'produto_id' => fn () => Produto::create([
                'produto_descricao' => 'Pizza '.fake()->unique()->numerify('####'),
                'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizza Grande '.fake()->unique()->numerify('####')])->id,
                'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            ])->id,
            'descricao' => null,
            'tempo_preparo_min' => 12,
            'foto_apresentacao' => null,
            'video_url' => null,
            'alergenos' => ['lactose', 'glúten'],
            'pontos_criticos' => null,
            'versao' => 1,
            'ativo' => true,
        ];
    }

    public function doProduto(Produto $produto): static
    {
        return $this->state(['produto_id' => $produto->id]);
    }
}
