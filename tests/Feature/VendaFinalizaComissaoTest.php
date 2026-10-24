<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusComissaoEnum;
use App\Enums\TipoDescontoEnum;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Comissao;
use App\Models\Cupom;
use App\Models\Influenciador;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobre especificamente o caminho que a investigação apontou como "sem
 * eventos Eloquent": VendaObserver::finalizarPedidosEMesas() usa
 * $pedido->updateQuietly() ao vincular o pedido à venda, então
 * PedidoObserver::updating() nunca dispara ali — o gancho de comissão
 * precisa ser chamado explicitamente dentro do VendaObserver.
 */
class VendaFinalizaComissaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_finalizar_venda_aprova_a_comissao_do_pedido_vinculado(): void
    {
        $influenciador = Influenciador::create([
            'influenciador_nome' => 'Fulano',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
            'influenciador_tipo_comissao' => TipoDescontoEnum::PERCENTUAL->value,
            'influenciador_valor_comissao' => 10,
        ]);

        $cliente = Cliente::create(['cliente_nome' => 'Cliente Teste', 'cliente_tipo' => 'PF']);

        $cupom = Cupom::create([
            'cupom_influenciador_id' => $influenciador->id,
            'cupom_codigo' => 'PROMO'.uniqid(),
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
        ]);

        $pedido = Pedido::create([
            'pedido_cliente_id' => $cliente->id,
            'pedido_cupom_id' => $cupom->id,
            'pedido_valor_total' => 90.0,
            'pedido_status' => 'PRONTO',
        ]);

        // Comissão nasce pendente na criação do pedido (PedidoObserver::created()).
        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->firstOrFail();
        $this->assertSame(StatusComissaoEnum::PENDENTE, $comissao->comissao_status);

        $venda = Venda::create(['venda_cliente_id' => $cliente->id, 'venda_status' => 'INICIADA']);

        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 90.0,
        ]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_venda_id' => $venda->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 90.0,
            'item_pedido_valor' => 90.0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        // Dispara VendaObserver::updated() -> finalizarPedidosEMesas() ->
        // $pedido->updateQuietly() -> ComissaoService::aprovar() explícito.
        $venda->update(['venda_status' => 'FINALIZADA']);

        $comissao->refresh();
        $this->assertSame(StatusComissaoEnum::APROVADA, $comissao->comissao_status);
        $this->assertNotNull($comissao->comissao_aprovada_em);

        // Confirma que o pedido realmente ficou vinculado à venda (efeito
        // colateral que o updateQuietly() já fazia antes desta mudança).
        $this->assertSame($venda->id, $pedido->fresh()->pedido_venda_id);
    }

    public function test_cancelar_o_pedido_cancela_a_comissao_via_pedidoobserver(): void
    {
        $influenciador = Influenciador::create([
            'influenciador_nome' => 'Fulano',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
        ]);

        $cliente = Cliente::create(['cliente_nome' => 'Cliente Teste', 'cliente_tipo' => 'PF']);

        $cupom = Cupom::create([
            'cupom_influenciador_id' => $influenciador->id,
            'cupom_codigo' => 'PROMO'.uniqid(),
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
        ]);

        $pedido = Pedido::create([
            'pedido_cliente_id' => $cliente->id,
            'pedido_cupom_id' => $cupom->id,
            'pedido_valor_total' => 90.0,
            'pedido_status' => 'ABERTO',
        ]);

        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->firstOrFail();
        $this->assertSame(StatusComissaoEnum::PENDENTE, $comissao->comissao_status);

        // update() normal dispara PedidoObserver::updating() de verdade.
        $pedido->update(['pedido_status' => 'CANCELADO']);

        $this->assertSame(StatusComissaoEnum::CANCELADA, $comissao->fresh()->comissao_status);
    }
}
