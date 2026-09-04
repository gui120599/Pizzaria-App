<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\OperarVenda;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\ItensVenda;
use App\Models\Maquininha;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\StonePedido;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class OperarVendaStonePagamentoTest extends TestCase
{
    use RefreshDatabase;

    private Venda $venda;

    private OpcoesPagamento $opcao;

    private Maquininha $maquininha;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stone.secret_key' => 'sk_test_abc',
            'services.stone.service_referer_name' => 'ref-123',
            'services.stone.base_url' => 'https://api.pagar.me/core/v5',
        ]);
        Http::preventStrayRequests();

        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);

        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);

        $categoria = Categoria::create(['categoria_nome' => 'Bebidas']);
        $produto = Produto::create([
            'produto_descricao' => 'Suco',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
            'produto_preco_venda' => 20.00,
        ]);

        $this->venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessao->id,
            'venda_valor_itens' => 20.00,
            'venda_valor_total' => 20.00,
        ]);

        ItensVenda::create([
            'item_numero' => 1,
            'item_venda_venda_id' => $this->venda->id,
            'item_venda_produto_id' => $produto->id,
            'item_venda_quantidade' => 1,
            'item_venda_quantidade_tributavel' => 1,
            'item_venda_valor_unitario' => 20.00,
            'item_venda_desconto' => 0,
            'item_venda_valor' => 20.00,
            'item_venda_valor_base_calculo' => 20.00,
            'item_venda_status' => 'INSERIDO',
        ]);

        $this->opcao = OpcoesPagamento::create([
            'opcaopag_nome' => 'Crédito Stone',
            'opcaopag_desc_nfe' => 'creditCard',
            'opcaopag_tipo_taxa' => 'N/A',
            'opcaopag_valor_percentual_taxa' => 0,
            'opcaopag_stone_integrada' => true,
        ]);
        $this->maquininha = Maquininha::create(['nome' => 'Balcão', 'operadora' => 'stone', 'numero_serie' => '6N021234']);
    }

    private function fakeStoneOk(): void
    {
        Http::fake([
            'api.pagar.me/core/v5/orders' => Http::response(['id' => 'or_xyz', 'code' => 'ABC1', 'status' => 'pending'], 200),
            'api.pagar.me/*' => Http::response([], 200),
        ]);
    }

    public function test_forma_stone_cria_pedido_e_abre_modal_sem_lancar_pagamento(): void
    {
        $this->fakeStoneOk();

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->opcao->id)
            ->set('stoneMaquininhaId', $this->maquininha->id)
            ->set('valorPagamento', 20.00)
            ->call('registrarPagamento')
            ->assertSet('modalStoneAberta', true)
            ->assertSet('stoneStatusModal', 'aguardando');

        $pedido = StonePedido::sole();
        $this->assertSame('or_xyz', $pedido->stp_order_id);
        $this->assertSame('aguardando', $pedido->stp_status->value);
        $this->assertSame(0, PagamentosVenda::count());
    }

    public function test_sem_maquininha_selecionada_mostra_erro(): void
    {
        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->opcao->id)
            ->set('valorPagamento', 20.00)
            ->call('registrarPagamento')
            ->assertHasErrors('valorPagamento')
            ->assertSet('modalStoneAberta', false);

        $this->assertSame(0, StonePedido::count());
    }

    public function test_verificar_status_stone_reflete_pagamento_do_webhook(): void
    {
        $this->fakeStoneOk();

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->opcao->id)
            ->set('stoneMaquininhaId', $this->maquininha->id)
            ->set('valorPagamento', 20.00)
            ->call('registrarPagamento');

        $pedido = StonePedido::sole();

        // Simula o webhook charge.paid da Stone.
        $this->postJson('/api/webhook/stone-connect', [
            'id' => 'hook_1',
            'type' => 'charge.paid',
            'data' => [
                'id' => 'ch_1', 'code' => '999', 'amount' => 2000, 'paid_amount' => 2000, 'status' => 'paid',
                'order' => ['id' => $pedido->stp_order_id, 'code' => $pedido->stp_order_code],
                'metadata' => ['authorization_code' => 'A1'],
            ],
        ])->assertOk();

        $component->call('verificarStatusStone')
            ->assertSet('stoneStatusModal', 'pago');

        $this->assertSame(1, PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->count());
    }

    public function test_cancelar_cobranca_stone_marca_pedido_como_cancelado(): void
    {
        $this->fakeStoneOk();

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->opcao->id)
            ->set('stoneMaquininhaId', $this->maquininha->id)
            ->set('valorPagamento', 20.00)
            ->call('registrarPagamento');

        $component->call('cancelarCobrancaStone')
            ->assertSet('stoneStatusModal', 'cancelado');

        $this->assertSame('cancelado', StonePedido::sole()->stp_status->value);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request->data() === ['status' => 'canceled']);
    }

    public function test_erro_na_criacao_do_pedido_mostra_status_erro(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['message' => 'terminal inválido'], 422)]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->opcao->id)
            ->set('stoneMaquininhaId', $this->maquininha->id)
            ->set('valorPagamento', 20.00)
            ->call('registrarPagamento')
            ->assertSet('stoneStatusModal', 'erro');

        $this->assertSame('falha', StonePedido::sole()->stp_status->value);
    }

    public function test_finalizar_venda_bloqueada_com_cobranca_stone_pendente(): void
    {
        $this->fakeStoneOk();

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->opcao->id)
            ->set('stoneMaquininhaId', $this->maquininha->id)
            ->set('valorPagamento', 20.00)
            ->call('registrarPagamento')
            ->call('finalizarVenda')
            ->assertHasErrors('finalizar');

        $this->assertSame('INICIADA', $this->venda->fresh()->venda_status);
    }
}
