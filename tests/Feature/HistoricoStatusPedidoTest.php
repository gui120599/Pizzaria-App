<?php

namespace Tests\Feature;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusPedidoEnum;
use App\Livewire\ConfirmacoesPedidos;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Models\Venda;
use App\Services\EntregaService;
use App\Services\PedidoStatusService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A timeline precisa nascer completa em TODOS os caminhos que escrevem
 * pedido_status, não só os que passam pelo PedidoStatusService — é o que
 * garante que "quanto tempo esse pedido ficou em cada etapa" seja
 * respondível de verdade, e não só nos casos em que o operador usou o
 * Painel de Pedidos.
 */
class HistoricoStatusPedidoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    private function gerente(): User
    {
        $user = User::factory()->create(['name_first' => 'Gerente']);
        $user->assignRole('Gerente');

        return $user;
    }

    public function test_criar_o_pedido_grava_o_status_inicial_sem_origem(): void
    {
        $pedido = Pedido::create([
            'pedido_status' => StatusPedidoEnum::ABERTO->value,
            'pedido_datahora_abertura' => now(),
        ]);

        $entrada = $pedido->historicoStatus()->first();

        $this->assertNotNull($entrada);
        $this->assertNull($entrada->hsp_status_de);
        $this->assertSame(StatusPedidoEnum::ABERTO->value, $entrada->hsp_status_para);
    }

    public function test_transicao_pelo_service_grava_de_para_e_usuario(): void
    {
        $ator = $this->gerente();
        $this->actingAs($ator);

        $pedido = Pedido::create([
            'pedido_status' => StatusPedidoEnum::ABERTO->value,
            'pedido_datahora_abertura' => now(),
        ]);

        app(PedidoStatusService::class)->aceitar($pedido, $ator);

        $ultima = $pedido->historicoStatus()->get()->last();

        $this->assertSame(StatusPedidoEnum::ABERTO->value, $ultima->hsp_status_de);
        $this->assertSame(StatusPedidoEnum::PREPARANDO->value, $ultima->hsp_status_para);
        $this->assertSame($ator->id, $ultima->hsp_user_id);
    }

    /**
     * EntregaService::aceitar() escreve por query builder (claim atômico) —
     * pula os eventos do Eloquent, então precisa gravar a transição ele mesmo.
     */
    public function test_claim_do_entregador_grava_a_transicao(): void
    {
        $entregador = User::factory()->create(['name_first' => 'Entregador']);

        $pedido = Pedido::create([
            'pedido_status' => StatusPedidoEnum::PRONTO->value,
            'pedido_datahora_abertura' => now(),
        ]);

        $aceito = app(EntregaService::class)->aceitar($pedido->id, $entregador);

        $this->assertTrue($aceito);

        $ultima = $pedido->historicoStatus()->get()->last();

        $this->assertSame(StatusPedidoEnum::PRONTO->value, $ultima->hsp_status_de);
        $this->assertSame(StatusPedidoEnum::EM_TRANSPORTE->value, $ultima->hsp_status_para);
        $this->assertSame($entregador->id, $ultima->hsp_user_id);
    }

    /**
     * VendaObserver finaliza pedido via updateQuietly() — também pula eventos.
     */
    public function test_pagamento_da_venda_grava_a_transicao_para_finalizado(): void
    {
        $ator = $this->gerente();
        $this->actingAs($ator);

        $pedido = Pedido::create([
            'pedido_status' => StatusPedidoEnum::ENTREGUE->value,
            'pedido_datahora_abertura' => now(),
        ]);

        $venda = Venda::create(['venda_status' => 'INICIADA']);

        $produto = Produto::create([
            'produto_descricao' => 'Refrigerante',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Bebidas'])->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
        ]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 40,
            'item_pedido_valor' => 40,
            'item_pedido_status' => 'INSERIDO',
            'item_pedido_venda_id' => $venda->id,
        ]);

        $venda->update(['venda_status' => 'FINALIZADA']);

        $ultima = $pedido->historicoStatus()->get()->last();

        $this->assertSame(StatusPedidoEnum::ENTREGUE->value, $ultima->hsp_status_de);
        $this->assertSame(StatusPedidoEnum::FINALIZADO->value, $ultima->hsp_status_para);
        $this->assertSame($ator->id, $ultima->hsp_user_id);
    }

    /** ConfirmacoesPedidos::confirmar() passou a usar o service. */
    public function test_confirmacoes_pedidos_confirmar_grava_a_transicao(): void
    {
        $ator = $this->gerente();
        $this->actingAs($ator);

        $entrega = OpcoesEntregas::create(['opcaoentrega_nome' => 'Delivery']);

        $pedido = Pedido::create([
            'pedido_status' => StatusPedidoEnum::INICIADO->value,
            'pedido_origem' => PedidoOrigemEnum::CARDAPIO->value,
            'pedido_opcaoentrega_id' => $entrega->id,
            'pedido_datahora_abertura' => now(),
        ]);

        Livewire::test(ConfirmacoesPedidos::class)->call('confirmar', $pedido->id);

        $ultima = $pedido->historicoStatus()->get()->last();

        $this->assertSame(StatusPedidoEnum::INICIADO->value, $ultima->hsp_status_de);
        $this->assertSame(StatusPedidoEnum::ABERTO->value, $ultima->hsp_status_para);
        $this->assertSame($ator->id, $ultima->hsp_user_id);
    }

    public function test_cancelar_pelo_service_grava_a_transicao(): void
    {
        $pedido = Pedido::create([
            'pedido_status' => StatusPedidoEnum::PREPARANDO->value,
            'pedido_datahora_abertura' => now(),
        ]);

        app(PedidoStatusService::class)->cancelar($pedido, null, MotivoCancelamentoEnum::OUTRO);

        $ultima = $pedido->historicoStatus()->get()->last();

        $this->assertSame(StatusPedidoEnum::PREPARANDO->value, $ultima->hsp_status_de);
        $this->assertSame(StatusPedidoEnum::CANCELADO->value, $ultima->hsp_status_para);
    }

    public function test_usuario_null_no_historico_exibe_sistema_como_padrao(): void
    {
        $pedido = Pedido::create([
            'pedido_status' => StatusPedidoEnum::ABERTO->value,
            'pedido_datahora_abertura' => now(),
        ]);

        $entrada = $pedido->historicoStatus()->first();

        $this->assertSame('Sistema', $entrada->usuario->name_first);
    }
}
