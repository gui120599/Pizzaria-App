<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\AtenderPedido;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Maquininha;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\StonePedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cobre o novo ponto de disparo "Cobrar na maquininha" no balcão/retirada
 * (AtenderPedido) — envia o Pedido, sem Venda prévia, para a maquininha
 * explicitamente escolhida pelo operador (R7 do plano de integração Stone).
 */
class AtenderPedidoStoneCobrancaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        config([
            'services.stone.secret_key' => 'sk_test_abc',
            'services.stone.service_referer_name' => 'ref-123',
            'services.stone.base_url' => 'https://api.pagar.me/core/v5',
            'services.stone.pedido_direto' => true,
        ]);
        Http::preventStrayRequests();
    }

    private function pedidoComItem(bool $combinadoStone = true): Pedido
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 40.0,
        ]);

        $pedido = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 40.0,
            'item_pedido_valor' => 40.0,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        $this->combinarPagamento($pedido, $combinadoStone);

        return $pedido;
    }

    private function combinarPagamento(Pedido $pedido, bool $stoneIntegrada = true): void
    {
        $opcao = OpcoesPagamento::create([
            'opcaopag_nome' => $stoneIntegrada ? 'Cartão Stone' : 'Dinheiro',
            'opcaopag_desc_nfe' => $stoneIntegrada ? 'creditCard' : 'cash',
            'opcaopag_tipo_taxa' => 'N/A',
            'opcaopag_valor_percentual_taxa' => 0,
            'opcaopag_stone_integrada' => $stoneIntegrada,
        ]);

        PagamentosPedido::create([
            'pg_pedido_pedido_id' => $pedido->id,
            'pg_pedido_opcaopagamento_id' => $opcao->id,
            'pg_pedido_opcaopagamento_nome' => $opcao->opcaopag_nome,
            'pg_pedido_valor' => (float) $pedido->pedido_valor_total,
            'pg_pedido_ordem' => 0,
        ]);
    }

    public function test_sem_maquininha_selecionada_mostra_erro(): void
    {
        $pedido = $this->pedidoComItem();

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->call('abrirModalStone')
            ->set('stoneMaquininhaId', null)
            ->call('enviarCobrancaStone')
            ->assertHasErrors(['stoneMaquininhaId']);

        $this->assertSame(0, StonePedido::count());
    }

    public function test_envia_cobranca_para_a_maquininha_escolhida(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['id' => 'or_abc', 'code' => 'COD1'], 200)]);

        $pedido = $this->pedidoComItem();
        $maquininhaBalcao = Maquininha::create(['nome' => 'Balcão', 'operadora' => 'stone', 'numero_serie' => '111']);
        Maquininha::create(['nome' => 'Entregador', 'operadora' => 'stone', 'numero_serie' => '222']);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->call('abrirModalStone')
            ->set('stoneMaquininhaId', $maquininhaBalcao->id)
            ->call('enviarCobrancaStone')
            ->assertHasNoErrors()
            ->assertSet('stoneStatusModal', 'aguardando');

        $stonePedido = StonePedido::sole();
        $this->assertSame($pedido->id, $stonePedido->stp_pedido_id);
        $this->assertSame($maquininhaBalcao->id, $stonePedido->stp_maquininha_id);
        $this->assertSame('pedido', $stonePedido->stp_origem->value);
        $this->assertNull($stonePedido->stp_venda_id);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/orders')
            && $request->data()['poi_payment_settings']['devices_serial_number'] === ['111']);
    }

    public function test_maquininha_sem_numero_de_serie_nao_aparece_na_lista(): void
    {
        Maquininha::create(['nome' => 'Sem série', 'operadora' => 'stone', 'numero_serie' => null]);
        $comSerie = Maquininha::create(['nome' => 'Com série', 'operadora' => 'stone', 'numero_serie' => '333']);
        $pedido = $this->pedidoComItem();

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->call('abrirModalStone')
            ->assertSet('modalStoneAberta', true)
            ->assertSee('Com série')
            ->assertDontSee('Sem série');

        $this->assertEquals([$comSerie->id], Maquininha::stoneDisponivel()->pluck('id')->all());
    }

    public function test_forma_de_pagamento_escolhida_usa_modo_direto(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['id' => 'or_abc', 'code' => 'COD1'], 200)]);

        $pedido = $this->pedidoComItem();
        $maquininha = Maquininha::create(['nome' => 'Balcão', 'operadora' => 'stone', 'numero_serie' => '111']);
        $credito = OpcoesPagamento::create([
            'opcaopag_nome' => 'Crédito Stone',
            'opcaopag_desc_nfe' => 'creditCard',
            'opcaopag_tipo_taxa' => 'N/A',
            'opcaopag_valor_percentual_taxa' => 0,
            'opcaopag_stone_integrada' => true,
        ]);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->call('abrirModalStone')
            ->set('stoneMaquininhaId', $maquininha->id)
            ->set('stoneOpcaoPagamentoId', $credito->id)
            ->call('enviarCobrancaStone')
            ->assertHasNoErrors();

        $stonePedido = StonePedido::sole();
        $this->assertSame('direto', $stonePedido->stp_modo->value);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/orders')
            && ($request->data()['poi_payment_settings']['payment_setup']['type'] ?? null) === 'credit');
    }

    public function test_botao_cobrar_some_quando_pagamento_combinado_nao_e_stone(): void
    {
        Maquininha::create(['nome' => 'Balcão', 'operadora' => 'stone', 'numero_serie' => '111']);
        $pedido = $this->pedidoComItem(combinadoStone: false);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->assertSet('podeCobrarStone', false)
            ->assertDontSee('Cobrar na maquininha')
            ->call('abrirModalStone')
            ->assertSet('modalStoneAberta', false);
    }

    public function test_botao_cobrar_aparece_quando_pagamento_combinado_e_stone(): void
    {
        Maquininha::create(['nome' => 'Balcão', 'operadora' => 'stone', 'numero_serie' => '111']);
        $pedido = $this->pedidoComItem();

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->assertSee('Cobrar na maquininha');
    }
}
