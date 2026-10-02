<?php

namespace Tests\Feature;

use App\Models\OpcoesEntregas;
use App\Models\PagamentosPedido;
use App\Models\Pedido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PedidoPDFQrCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('ALTER TABLE opcoes_entregas AUTO_INCREMENT = 1');
    }

    public function test_ticket_de_pedido_delivery_inclui_qrcode(): void
    {
        OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada']);
        OpcoesEntregas::create(['opcaoentrega_nome' => 'Comer no Local']);
        $entrega = OpcoesEntregas::create(['opcaoentrega_nome' => 'Entrega']);

        $pedido = Pedido::create([
            'pedido_opcaoentrega_id' => $entrega->id,
            'pedido_status' => 'PRONTO',
            'pedido_endereco_entrega' => 'Rua Teste, 123',
            'pedido_valor_total' => 50.0,
        ]);

        $this->get(route('pedido.imprimir', ['id' => $pedido->id]))
            ->assertOk()
            ->assertSee('ESCANEIE PRA SAIR / CONFIRMAR ENTREGA')
            ->assertSee('data:image/png;base64,', false);
    }

    public function test_ticket_de_pedido_retirada_nao_inclui_qrcode(): void
    {
        $retirada = OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada']);

        $pedido = Pedido::create([
            'pedido_opcaoentrega_id' => $retirada->id,
            'pedido_status' => 'PRONTO',
            'pedido_valor_total' => 30.0,
        ]);

        $this->get(route('pedido.imprimir', ['id' => $pedido->id]))
            ->assertOk()
            ->assertDontSee('ESCANEIE PRA SAIR / CONFIRMAR ENTREGA');
    }

    public function test_ticket_mostra_as_formas_de_pagamento_combinadas_com_o_troco(): void
    {
        $pedido = Pedido::create(['pedido_status' => 'ABERTO', 'pedido_valor_total' => 80.0]);
        PagamentosPedido::create(['pg_pedido_pedido_id' => $pedido->id, 'pg_pedido_opcaopagamento_nome' => 'Pix', 'pg_pedido_valor' => 30, 'pg_pedido_ordem' => 0]);
        PagamentosPedido::create(['pg_pedido_pedido_id' => $pedido->id, 'pg_pedido_opcaopagamento_nome' => 'Dinheiro', 'pg_pedido_valor' => 50, 'pg_pedido_valor_troco_para' => 100, 'pg_pedido_ordem' => 1]);

        $this->get(route('pedido.imprimir', ['id' => $pedido->id]))
            ->assertOk()
            ->assertSee('Pix, Dinheiro')
            ->assertSeeInOrder(['Pix: R$ 30,00', 'Dinheiro: R$ 50,00', 'Troco para R$ 100,00', 'levar R$ 50,00']);
    }

    public function test_ticket_de_pedido_sem_pagamento_combinado_usa_a_descricao_livre(): void
    {
        $pedido = Pedido::create(['pedido_status' => 'ABERTO', 'pedido_valor_total' => 40.0, 'pedido_descricao_pagamento' => 'Cartão de Crédito']);

        $this->get(route('pedido.imprimir', ['id' => $pedido->id]))
            ->assertOk()
            ->assertSee('Cartão de Crédito');
    }
}
