<?php

namespace Tests\Feature;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusPedidoEnum;
use App\Exceptions\TransicaoPedidoInvalidaException;
use App\Models\Categoria;
use App\Models\FichaTecnicaItem;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Models\Venda;
use App\Services\PedidoStatusService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PedidoStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private PedidoStatusService $service;

    private int $categoriaId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PedidoStatusService::class);
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Teste'])->id;
        $this->actingAs(User::factory()->create(['name_first' => 'Operador']));
    }

    private function opcaoEntrega(bool $requerEndereco): OpcoesEntregas
    {
        return OpcoesEntregas::create([
            'opcaoentrega_nome' => $requerEndereco ? 'Delivery' : 'Balcão',
            'opcaoentrega_requer_endereco' => $requerEndereco,
        ]);
    }

    private function pedido(StatusPedidoEnum $status, array $extras = []): Pedido
    {
        return Pedido::create($extras + [
            'pedido_status' => $status->value,
            'pedido_datahora_abertura' => now(),
        ]);
    }

    /** Pedido com ficha técnica, para provar que o observer rodou. */
    private function pedidoComInsumo(StatusPedidoEnum $status): array
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

        $pedido = $this->pedido($status);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $pizza->id,
            'item_pedido_quantidade' => 2,
            'item_pedido_valor_unitario' => 30,
            'item_pedido_valor' => 60,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return [$pedido, $farinha];
    }

    public function test_confirmar_leva_de_iniciado_para_aberto(): void
    {
        $pedido = $this->pedido(StatusPedidoEnum::INICIADO);

        $this->service->confirmar($pedido);

        $this->assertSame(StatusPedidoEnum::ABERTO->value, $pedido->refresh()->pedido_status);
    }

    public function test_aceitar_leva_para_preparando_e_grava_a_datahora(): void
    {
        $pedido = $this->pedido(StatusPedidoEnum::ABERTO);

        $this->service->aceitar($pedido);
        $pedido->refresh();

        $this->assertSame(StatusPedidoEnum::PREPARANDO->value, $pedido->pedido_status);
        $this->assertNotNull($pedido->pedido_datahora_preparo);
    }

    /**
     * O teste mais importante da suíte: prova que a gravação passou pelo model e
     * portanto pelo PedidoObserver. Se alguém trocar o save() por
     * updateQuietly() ou por um update em massa, o estoque para de ser baixado
     * sem nenhum erro aparecer — e é este teste que pega.
     */
    public function test_aceitar_baixa_o_estoque_pelo_observer(): void
    {
        [$pedido, $farinha] = $this->pedidoComInsumo(StatusPedidoEnum::ABERTO);

        $this->service->aceitar($pedido);

        // 2 pizzas x 0,3 kg = 0,6 kg consumidos.
        $this->assertEqualsWithDelta(9.4, (float) $farinha->refresh()->produto_saldo_estoque, 0.001);
    }

    public function test_avanca_a_cadeia_inteira_gravando_cada_datahora(): void
    {
        $pedido = $this->pedido(StatusPedidoEnum::ABERTO, [
            'pedido_opcaoentrega_id' => $this->opcaoEntrega(requerEndereco: true)->id,
        ]);

        foreach ([
            StatusPedidoEnum::PREPARANDO,
            StatusPedidoEnum::PRONTO,
            StatusPedidoEnum::EM_TRANSPORTE,
            StatusPedidoEnum::ENTREGUE,
        ] as $esperado) {
            $pedido = $this->service->avancar($pedido);

            $this->assertSame($esperado->value, $pedido->pedido_status);
            $this->assertNotNull(
                $pedido->{$esperado->campoDataHora()},
                "{$esperado->campoDataHora()} não foi gravado",
            );
        }
    }

    public function test_pedido_de_balcao_pula_o_estagio_de_transporte(): void
    {
        $pedido = $this->pedido(StatusPedidoEnum::PRONTO, [
            'pedido_opcaoentrega_id' => $this->opcaoEntrega(requerEndereco: false)->id,
        ]);

        $pedido = $this->service->avancar($pedido);

        $this->assertSame(StatusPedidoEnum::ENTREGUE->value, $pedido->pedido_status);
    }

    public function test_pedido_de_delivery_passa_pelo_estagio_de_transporte(): void
    {
        $pedido = $this->pedido(StatusPedidoEnum::PRONTO, [
            'pedido_opcaoentrega_id' => $this->opcaoEntrega(requerEndereco: true)->id,
        ]);

        $pedido = $this->service->avancar($pedido);

        $this->assertSame(StatusPedidoEnum::EM_TRANSPORTE->value, $pedido->pedido_status);
    }

    public function test_despachar_atribui_o_entregador(): void
    {
        $entregador = User::factory()->create(['name_first' => 'Entregador']);
        $pedido = $this->pedido(StatusPedidoEnum::PRONTO, [
            'pedido_opcaoentrega_id' => $this->opcaoEntrega(requerEndereco: true)->id,
        ]);

        $pedido = $this->service->despachar($pedido, entregador: $entregador);

        $this->assertSame(StatusPedidoEnum::EM_TRANSPORTE->value, $pedido->pedido_status);
        $this->assertSame($entregador->id, $pedido->pedido_usuario_entrega_id);
    }

    public function test_pedido_ja_pago_encerra_em_finalizado_e_nao_entregue(): void
    {
        $pedido = $this->pedido(StatusPedidoEnum::EM_TRANSPORTE, [
            'pedido_datahora_finalizado' => now()->subMinutes(10),
        ]);

        $pedido = $this->service->marcarEntregue($pedido);

        $this->assertSame(StatusPedidoEnum::FINALIZADO->value, $pedido->pedido_status);
        // A hora da entrega é registrada mesmo terminando em FINALIZADO.
        $this->assertNotNull($pedido->pedido_datahora_entrega);
    }

    public function test_cancelar_registra_motivo_usuario_e_datahora(): void
    {
        $this->seed(PermissionSeeder::class);
        $ator = User::factory()->create(['name_first' => 'Gerente']);
        $ator->assignRole('Gerente');

        $pedido = $this->pedido(StatusPedidoEnum::PREPARANDO);

        $pedido = $this->service->cancelar($pedido, $ator, MotivoCancelamentoEnum::PRODUTO_INDISPONIVEL);

        $this->assertSame(StatusPedidoEnum::CANCELADO->value, $pedido->pedido_status);
        $this->assertSame(MotivoCancelamentoEnum::PRODUTO_INDISPONIVEL, $pedido->pedido_motivo_cancelamento);
        $this->assertSame($ator->id, $pedido->pedido_usuario_cancelou_id);
        $this->assertNotNull($pedido->pedido_datahora_cancelado);
    }

    public function test_cancelar_a_partir_de_preparando_estorna_o_estoque(): void
    {
        [$pedido, $farinha] = $this->pedidoComInsumo(StatusPedidoEnum::ABERTO);

        $this->service->aceitar($pedido);
        $this->assertEqualsWithDelta(9.4, (float) $farinha->refresh()->produto_saldo_estoque, 0.001);

        $this->service->cancelar($pedido->refresh(), null, MotivoCancelamentoEnum::OUTRO);

        $this->assertEqualsWithDelta(10.0, (float) $farinha->refresh()->produto_saldo_estoque, 0.001);
    }

    public function test_pedido_ja_pago_nao_pode_ser_cancelado(): void
    {
        $venda = Venda::create(['venda_status' => 'FINALIZADA']);
        $pedido = $this->pedido(StatusPedidoEnum::PRONTO, ['pedido_venda_id' => $venda->id]);

        $this->expectException(TransicaoPedidoInvalidaException::class);
        $this->expectExceptionMessage('já está pago');

        $this->service->cancelar($pedido, null, MotivoCancelamentoEnum::OUTRO);
    }

    public function test_rejeitar_ignora_o_guard_de_pagamento_mas_cancela(): void
    {
        $pedido = $this->pedido(StatusPedidoEnum::ABERTO);

        $pedido = $this->service->rejeitar($pedido, null, MotivoCancelamentoEnum::CLIENTE_DESISTIU);

        $this->assertSame(StatusPedidoEnum::CANCELADO->value, $pedido->pedido_status);
        $this->assertSame(MotivoCancelamentoEnum::CLIENTE_DESISTIU, $pedido->pedido_motivo_cancelamento);
    }

    public function test_pedido_terminal_nao_pode_ser_cancelado(): void
    {
        $pedido = $this->pedido(StatusPedidoEnum::FINALIZADO);

        $this->expectException(TransicaoPedidoInvalidaException::class);

        $this->service->cancelar($pedido, null, MotivoCancelamentoEnum::OUTRO);
    }

    public function test_pedido_entregue_nao_tem_proxima_etapa(): void
    {
        $pedido = $this->pedido(StatusPedidoEnum::ENTREGUE);

        $this->expectException(TransicaoPedidoInvalidaException::class);
        $this->expectExceptionMessage('não tem próxima etapa');

        $this->service->avancar($pedido);
    }

    public function test_aceitar_um_pedido_que_nao_esta_aberto_e_recusado(): void
    {
        $pedido = $this->pedido(StatusPedidoEnum::PRONTO);

        $this->expectException(TransicaoPedidoInvalidaException::class);

        $this->service->aceitar($pedido);
    }

    /**
     * O cenário do duplo clique / duas abas: a segunda chamada trabalha sobre um
     * model stale. Sem o lock + recheck, ela reaplicaria a transição e baixaria
     * o estoque de novo.
     */
    public function test_transicao_concorrente_nao_baixa_o_estoque_duas_vezes(): void
    {
        [$pedido, $farinha] = $this->pedidoComInsumo(StatusPedidoEnum::ABERTO);
        $stale = Pedido::find($pedido->id);

        $this->service->aceitar($pedido);
        $this->assertEqualsWithDelta(9.4, (float) $farinha->refresh()->produto_saldo_estoque, 0.001);

        try {
            $this->service->aceitar($stale);
            $this->fail('A segunda transição deveria ter sido recusada.');
        } catch (TransicaoPedidoInvalidaException $e) {
            $this->assertStringContainsString('já está em', $e->getMessage());
        }

        $this->assertEqualsWithDelta(9.4, (float) $farinha->refresh()->produto_saldo_estoque, 0.001);
    }
}
