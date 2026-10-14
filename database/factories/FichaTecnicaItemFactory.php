<?php

namespace Database\Factories;

use App\Enums\Cozinha\EscopoIngrediente;
use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\FichaTecnicaItem;
use App\Models\Produto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FichaTecnicaItem>
 */
class FichaTecnicaItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fti_produto_id' => fn () => $this->produto(ProdutoTipoEnum::PRODUZIDO, 'Pizza')->id,
            'fti_insumo_id' => fn () => $this->produto(ProdutoTipoEnum::INSUMO, 'Insumo')->id,
            'fti_quantidade' => 0.1,
            'fti_unidade' => 'KG',
            'fti_percentual_perda' => 0,
            'fti_escopo' => EscopoIngrediente::Cobertura,
            'fti_opcional_ref' => null,
            'fti_ordem' => 0,
        ];
    }

    public function escopo(EscopoIngrediente $escopo): static
    {
        return $this->state(['fti_escopo' => $escopo]);
    }

    private function produto(ProdutoTipoEnum $tipo, string $prefixo): Produto
    {
        return Produto::create([
            'produto_descricao' => $prefixo.' '.fake()->unique()->numerify('####'),
            'produto_categoria_id' => Categoria::create(['categoria_nome' => $prefixo.' '.fake()->unique()->numerify('####')])->id,
            'produto_tipo' => $tipo->value,
        ]);
    }
}
