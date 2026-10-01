<?php

namespace Database\Factories;

use App\Models\HistoricoStatusPedido;
use App\Models\Pedido;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HistoricoStatusPedido>
 */
class HistoricoStatusPedidoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'hsp_pedido_id' => Pedido::factory(),
            'hsp_status_de' => null,
            'hsp_status_para' => 'ABERTO',
            'hsp_user_id' => null,
        ];
    }
}
