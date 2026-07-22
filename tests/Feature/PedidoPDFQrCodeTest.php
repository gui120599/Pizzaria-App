<?php

namespace Tests\Feature;

use App\Models\OpcoesEntregas;
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
}
