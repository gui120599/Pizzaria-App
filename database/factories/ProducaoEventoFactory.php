<?php

namespace Database\Factories;

use App\Enums\Cozinha\AcaoProducao;
use App\Enums\Cozinha\StatusItemProducao;
use App\Models\ProducaoEvento;
use App\Models\ProducaoItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProducaoEvento>
 */
class ProducaoEventoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'producao_item_id' => ProducaoItem::factory(),
            'pedido_id' => fn (array $atributos) => ProducaoItem::find($atributos['producao_item_id'])->pedido_id,
            'setor_producao_id' => fn (array $atributos) => ProducaoItem::find($atributos['producao_item_id'])->setor_producao_id,
            'acao' => AcaoProducao::Gerado,
            'de_status' => null,
            'para_status' => StatusItemProducao::NaFila,
            'dados' => null,
        ];
    }
}
