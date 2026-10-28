<?php

namespace Database\Factories;

use App\Enums\BaseVencimentoEnum;
use App\Enums\RegraDataEntradaEnum;
use App\Models\FornecedorCondicaoCompra;
use App\Models\Prestador;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FornecedorCondicaoCompra>
 */
class FornecedorCondicaoCompraFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fcc_prestador_id' => fn (): int => Prestador::create([
                'tipo' => 'pj',
                'categoria' => 'fornecedor',
                'razao_social' => fake()->company(),
            ])->id,
            'fcc_prazo_base' => BaseVencimentoEnum::Entrada,
            'fcc_data_entrada_regra' => RegraDataEntradaEnum::Hoje,
        ];
    }

    public function dataEntradaPelaEmissao(): static
    {
        return $this->state(['fcc_data_entrada_regra' => RegraDataEntradaEnum::Emissao]);
    }
}
