<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\QuantidadeSabor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuantidadeSabor>
 */
class QuantidadeSaborFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quantidade_sabor_categoria_id' => Categoria::factory(),
            'quantidade_sabor_descricao' => 'Meia a meia',
            'quantidade_sabor_quantidade' => 2,
            'quantidade_sabor_percentuais' => QuantidadeSabor::percentuaisIguais(2),
            'quantidade_sabor_ordem' => 2,
        ];
    }

    public function sabores(int $quantidade): static
    {
        return $this->state(fn (): array => [
            'quantidade_sabor_descricao' => match ($quantidade) {
                1 => 'Sabor único',
                2 => 'Meia a meia',
                default => "{$quantidade} sabores",
            },
            'quantidade_sabor_quantidade' => $quantidade,
            'quantidade_sabor_percentuais' => QuantidadeSabor::percentuaisIguais($quantidade),
            'quantidade_sabor_ordem' => $quantidade,
        ]);
    }
}
