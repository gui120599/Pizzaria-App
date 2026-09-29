<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\StonePedidos\Pages\ManageStonePedidos;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\StonePedido;
use App\Models\User;
use App\Models\Venda;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cobre a recuperação manual do R6: StonePedido pago sem conseguir gerar a
 * Venda sozinho (ambiguidade de sessão de caixa) — o back office escolhe a
 * sessão e a ação "Gerar venda" tenta de novo.
 */
class StonePedidoResourceGerarVendaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        config(['services.stone.secret_key' => 'sk_test']);
    }

    public function test_gerar_venda_cria_e_finaliza_a_venda_na_sessao_escolhida(): void
    {
        $user = User::factory()->admin()->create(['name_first' => 'Caixa']);
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $sessaoCaixa = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_saldo_final' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);

        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50.00,
        ]);

        $pedido = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 50.00,
            'item_pedido_valor' => 50.00,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        $stonePedido = StonePedido::create([
            'stp_pedido_id' => $pedido->id,
            'stp_origem' => 'pedido',
            'stp_order_id' => 'or_1',
            'stp_order_code' => 'ABC1',
            'stp_valor_solicitado' => 50.00,
            'stp_valor_pago' => 50.00,
            'stp_status' => 'pago',
            'stp_modo' => 'listado',
            'stp_erro' => 'Não foi possível determinar a sessão de caixa: 0 sessão(ões) ABERTA(S).',
        ]);

        Livewire::test(ManageStonePedidos::class)
            ->callAction(TestAction::make('gerarVenda')->table($stonePedido), [
                'sessaocaixa_id' => $sessaoCaixa->id,
            ])
            ->assertNotified();

        $venda = Venda::sole();
        $this->assertSame('FINALIZADA', $venda->venda_status);
        $this->assertSame($sessaoCaixa->id, $venda->venda_sessao_caixa_id);
        $this->assertSame($venda->id, $stonePedido->fresh()->stp_venda_id);
        $this->assertSame('FINALIZADO', $pedido->fresh()->pedido_status);
    }
}
