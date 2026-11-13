<?php

namespace Database\Factories;

use App\Enums\Compras\StatusPendencia;
use App\Enums\Compras\TipoPendenciaCompra;
use App\Models\Compra;
use App\Models\CompraPendencia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompraPendencia>
 */
class CompraPendenciaFactory extends Factory
{
    protected $model = CompraPendencia::class;

    public function definition(): array
    {
        return [
            'compra_id' => fn (): int => Compra::create(['compra_numero' => (string) fake()->numberBetween(1, 99999)])->id,
            'tipo' => TipoPendenciaCompra::ProdutoNaoIdentificado,
            'status' => StatusPendencia::Aberta,
            'dados' => [],
        ];
    }
}
