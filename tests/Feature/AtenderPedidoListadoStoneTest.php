<?php

namespace Tests\Feature;

use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoStatus;
use App\Filament\Pages\AtenderPedido;
use App\Models\Categoria;
use App\Models\Maquininha;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\StonePedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CenarioStone;
use Tests\TestCase;

/**
 * Revisão 1 (D5): o atendimento de pedido envia SEMPRE em Listado, sem forma de
 * pagamento e sem esperar o pagamento na tela. O entregador escolhe o tipo no POS.
 */
class AtenderPedidoListadoStoneTest extends TestCase
{
    use CenarioStone;
    use RefreshDatabase;

    private Maquininha $sunmi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurarStone();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->sunmi = $this->maquininhaConnect('Sunmi', '111');
    }

    private function formularioNovoPedido(): Testable
    {
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => 'produzido',
            'produto_preco_venda' => 50.0,
        ]);
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);

        return Livewire::test(AtenderPedido::class)
            ->set('itensCarrinho', [[
                'produto_id' => $produto->id, 'quantidade' => 1, 'valor_unitario' => 50.0, 'desconto' => 0, 'valor' => 50.0,
                'adicionais_valor' => 0, 'observacao' => '', 'adicionais' => [],
            ]])
            ->set('clienteData', ['semCliente' => true])
            ->set('entregaPagamentoData', ['opcaoEntregaId' => null, 'pagamentos' => [
                ['opcaoPagamentoId' => $dinheiro->id, 'valor' => '50,00', 'trocoPara' => null],
            ]]);
    }

    /** @return array<int, Request> */
    private function postsDeCriacao(): array
    {
        return Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/orders'))
            ->map(fn (array $par) => $par[0])
            ->all();
    }

    public function test_r1_cobrar_na_maquininha_envia_em_listado_fecha_o_modal_e_nao_deixa_polling(): void
    {
        $this->fakeStoneCriaPedidos('or_listado');
        $pedido = $this->pedidoComTotal(80);
        $this->combinarPagamento($pedido, $this->formaCredito());

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->call('abrirModalStone')
            ->set('stoneMaquininhaId', $this->sunmi->id)
            ->call('enviarCobrancaStone')
            ->assertHasNoErrors()
            ->assertNotified("Pedido #{$pedido->id} enviado para a maquininha")
            ->assertSet('modalStoneAberta', false)
            ->assertSet('stonePedidoId', null)
            ->assertRedirect(AtenderPedido::getUrl(['pedido' => $pedido->id]));

        $stonePedido = StonePedido::sole();
        $this->assertSame(StonePedidoModo::Listado, $stonePedido->stp_modo);
        $this->assertNull($stonePedido->stp_opcaopagamento_id);
        $this->assertSame(StonePedidoStatus::Aguardando, $stonePedido->stp_status);
        $this->assertSame(80.0, (float) $stonePedido->stp_valor_solicitado);

        [$post] = $this->postsDeCriacao();
        $this->assertArrayNotHasKey('payment_setup', $post['poi_payment_settings']);
        $this->assertSame(['111'], $post['poi_payment_settings']['devices_serial_number']);
    }

    public function test_r1_o_modal_nao_oferece_polling_depois_do_envio(): void
    {
        $this->fakeStoneCriaPedidos('or_sem_polling');
        $pedido = $this->pedidoComTotal(80);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->call('abrirModalStone')
            ->set('stoneMaquininhaId', $this->sunmi->id)
            ->call('enviarCobrancaStone')
            ->assertDontSeeHtml('wire:poll');
    }

    public function test_r2_salvar_e_cobrar_em_pedido_novo_cria_uma_vez_envia_em_listado_e_volta_em_branco(): void
    {
        $this->fakeStoneCriaPedidos('or_novo');

        $this->formularioNovoPedido()
            ->call('salvarECobrar')
            ->assertSet('modalStoneAberta', true)
            ->assertSet('stoneStatusModal', 'form')
            ->set('stoneMaquininhaId', $this->sunmi->id)
            ->call('enviarCobrancaStone')
            ->assertNotified()
            ->assertSet('modalStoneAberta', false)
            ->assertRedirect(AtenderPedido::getUrl());

        $this->assertSame(1, Pedido::count());
        $stonePedido = StonePedido::sole();
        $this->assertSame(StonePedidoModo::Listado, $stonePedido->stp_modo);
        $this->assertSame(Pedido::sole()->id, $stonePedido->stp_pedido_id);
        $this->assertSame(50.0, (float) $stonePedido->stp_valor_solicitado);
    }

    public function test_r3_resultado_desconhecido_mantem_o_modal_aberto_com_reconsultar(): void
    {
        Http::fake(['api.pagar.me/*' => Http::sequence()->pushFailedConnection()]);
        $pedido = $this->pedidoComTotal(80);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->call('abrirModalStone')
            ->set('stoneMaquininhaId', $this->sunmi->id)
            ->call('enviarCobrancaStone')
            ->assertSet('modalStoneAberta', true)
            ->assertSet('stoneStatusModal', 'indeterminado')
            ->assertSee('Reconsultar na Stone')
            ->assertNoRedirect();

        $stonePedido = StonePedido::sole();
        $this->assertSame(StonePedidoStatus::Indeterminado, $stonePedido->stp_status);
        $this->assertSame(StonePedidoModo::Listado, $stonePedido->stp_modo);
    }

    public function test_falha_comprovada_mantem_o_modal_com_o_erro_e_permite_tentar_de_novo(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['message' => 'Serial inválido'], 422)]);
        $pedido = $this->pedidoComTotal(80);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->call('abrirModalStone')
            ->set('stoneMaquininhaId', $this->sunmi->id)
            ->call('enviarCobrancaStone')
            ->assertSet('modalStoneAberta', true)
            ->assertSet('stoneStatusModal', 'erro')
            ->assertSee('Tentar de novo')
            ->assertNoRedirect()
            ->call('voltarAoFormularioStone')
            ->assertSet('stoneStatusModal', 'form')
            ->assertSet('stoneErroModal', null);
    }

    public function test_r4_reabrir_com_cobranca_pendente_mostra_resumo_e_acoes(): void
    {
        $pedido = $this->pedidoComTotal(80);
        $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_valor_solicitado' => 30]);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->assertSee('Na maquininha Sunmi desde')
            ->call('abrirModalStone')
            ->assertSet('modalStoneAberta', true)
            ->assertSet('stoneStatusModal', 'aguardando')
            ->assertSee('Sunmi')
            ->assertSee('R$ 30,00')
            ->assertSee('Aguardando')
            ->assertSee('Cancelar cobrança')
            ->assertSee('Enviar valor restante')
            ->assertSee('Fechar')
            ->assertDontSeeHtml('wire:poll')
            ->assertDontSee('Forma de pagamento (opcional)');
    }

    public function test_r4_sem_saldo_a_cobrar_o_resumo_nao_oferece_enviar_valor_restante(): void
    {
        $pedido = $this->pedidoComTotal(80);
        $this->cobrancaDoPedido($pedido, $this->sunmi);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->call('abrirModalStone')
            ->assertSet('stoneStatusModal', 'aguardando')
            ->assertSee('Cancelar cobrança')
            ->assertDontSee('Enviar valor restante');
    }

    public function test_r4_enviar_valor_restante_cria_segunda_cobranca_listado_so_com_o_saldo(): void
    {
        $this->fakeStoneCriaPedidos('or_restante');
        $pedido = $this->pedidoComTotal(80);
        $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_valor_solicitado' => 30]);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->call('abrirModalStone')
            ->call('prepararEnvioRestanteStone')
            ->assertSet('stoneStatusModal', 'form')
            ->set('stoneMaquininhaId', $this->sunmi->id)
            ->call('enviarCobrancaStone')
            ->assertSet('modalStoneAberta', false);

        $this->assertSame(2, StonePedido::count());
        $this->assertSame(50.0, (float) StonePedido::orderByDesc('id')->first()->stp_valor_solicitado);
    }

    public function test_r4_cancelar_cobranca_do_resumo_cancela_na_stone_e_fecha_o_modal(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);
        $pedido = $this->pedidoComTotal(80);
        $stonePedido = $this->cobrancaDoPedido($pedido, $this->sunmi);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->call('abrirModalStone')
            ->call('cancelarCobrancaStone')
            ->assertSet('modalStoneAberta', false);

        $this->assertSame(StonePedidoStatus::Cancelado, $stonePedido->fresh()->stp_status);
    }

    public function test_r5_o_select_de_forma_nao_existe_mais_no_modal_do_atendimento(): void
    {
        $pedido = $this->pedidoComTotal(80);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->call('abrirModalStone')
            ->assertSet('stoneStatusModal', 'form')
            ->assertDontSee('Forma de pagamento (opcional)')
            ->assertDontSee('Escolher na própria maquininha')
            ->assertDontSeeHtml('stoneOpcaoPagamentoId');

        $this->assertFalse(property_exists(AtenderPedido::class, 'stoneOpcaoPagamentoId'));
        $this->assertFalse(method_exists(AtenderPedido::class, 'opcoesPagamentoStone'));
    }
}
