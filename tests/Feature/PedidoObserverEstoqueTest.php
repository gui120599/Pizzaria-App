<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\FichaTecnicaItem;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobre a baixa/estorno automáticos de estoque via PedidoObserver
 * (transição para PREPARANDO / cancelamento), incluindo a expansão por
 * ficha técnica — mesmo caminho refatorado para reusar EstoqueService::itensConsumo().
 */
class PedidoObserverEstoqueTest extends TestCase
{
    use RefreshDatabase;

    private int $categoriaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Teste'])->id;
        $this->actingAs(User::factory()->create(['name_first' => 'Operador']));
    }

    private function pedido(): Pedido
    {
        return Pedido::create([
            'pedido_status' => 'ABERTO',
            'pedido_datahora_abertura' => now(),
        ]);
    }

    public function test_baixa_e_estorno_via_ficha_tecnica_ao_mudar_status_do_pedido(): void
    {
        $farinha = Produto::create([
            'produto_descricao' => 'Farinha',
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => ProdutoTipoEnum::INSUMO->value,
            'produto_controla_estoque' => true,
            'produto_saldo_estoque' => 10,
            'produto_custo_medio' => 2.00,
        ]);

        $pizza = Produto::create([
            'produto_descricao' => 'Pizza',
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_ficha_rendimento' => 1,
        ]);

        FichaTecnicaItem::create([
            'fti_produto_id' => $pizza->id,
            'fti_insumo_id' => $farinha->id,
            'fti_quantidade' => 0.3,
            'fti_percentual_perda' => 0,
        ]);

        $pedido = $this->pedido();
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $pizza->id,
            'item_pedido_quantidade' => 2,
            'item_pedido_valor_unitario' => 30,
            'item_pedido_valor' => 60,
            'item_pedido_status' => 'INSERIDO',
        ]);

        // Baixa: 2 pizzas x 0,3 kg farinha = 0,6 kg consumidos.
        $pedido->update(['pedido_status' => 'PREPARANDO']);
        $this->assertEqualsWithDelta(9.4, (float) $farinha->refresh()->produto_saldo_estoque, 0.001);

        // Estorno: cancelamento após já ter baixado devolve o consumido.
        $pedido->update(['pedido_status' => 'CANCELADO']);
        $this->assertEqualsWithDelta(10.0, (float) $farinha->refresh()->produto_saldo_estoque, 0.001);
    }
}
