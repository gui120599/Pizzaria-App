<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoOrigem;
use App\Enums\StonePedidoStatus;
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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\CenarioStone;
use Tests\TestCase;

/**
 * PDV: parte da venda passou na maquininha (Listado) e o restante foi pago em
 * dinheiro. A cobrança aberta travava a finalização e não podia ser cancelada
 * (já recebeu valor): o caixa precisa acompanhar e encerrar.
 */
class OperarVendaStoneEncerrarRestanteTest extends TestCase
{
    use CenarioStone;
    use RefreshDatabase;

    private Venda $venda;

    private Maquininha $sunmi;

    private OpcoesPagamento $dinheiro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurarStone();
        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);
        $this->sunmi = $this->maquininhaConnect('Sunmi', '111');

        $sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);
        $produto = Produto::create([
            'produto_descricao' => 'Pizza',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 100.00,
        ]);

        $this->venda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $sessao->id, 'venda_valor_itens' => 100, 'venda_valor_total' => 100, 'venda_valor_pago' => 0]);
        ItensVenda::create([
            'item_numero' => 1, 'item_venda_venda_id' => $this->venda->id, 'item_venda_produto_id' => $produto->id,
            'item_venda_quantidade' => 1, 'item_venda_quantidade_tributavel' => 1, 'item_venda_valor_unitario' => 100,
            'item_venda_desconto' => 0, 'item_venda_valor' => 100, 'item_venda_valor_base_calculo' => 100, 'item_venda_status' => 'INSERIDO',
        ]);

        $this->dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);
    }

    private function cobranca(StonePedidoStatus $status, float $pago = 0, array $atributos = []): StonePedido
    {
        return StonePedido::create($atributos + [
            'stp_venda_id' => $this->venda->id,
            'stp_maquininha_id' => $this->sunmi->id,
            'stp_origem' => StonePedidoOrigem::Venda,
            'stp_order_id' => 'or_'.uniqid(),
            'stp_valor_solicitado' => 100,
            'stp_valor_pago' => $pago,
            'stp_status' => $status,
            'stp_modo' => StonePedidoModo::Listado,
        ]);
    }

    private function pagarEmDinheiro(float $valor): void
    {
        PagamentosVenda::create([
            'pg_venda_venda_id' => $this->venda->id, 'pg_venda_opcaopagamento_id' => $this->dinheiro->id,
            'pg_venda_valor_pagamento' => $valor, 'pg_venda_valor_recebido' => $valor, 'pg_venda_valor_pago_pelo_cliente' => $valor, 'pg_venda_valor_troco' => 0,
        ]);
        $this->venda->increment('venda_valor_pago', $valor);
    }

    public function test_cobranca_aberta_aparece_na_venda_e_pode_ser_reaberta(): void
    {
        $cobranca = $this->cobranca(StonePedidoStatus::PagoParcial, 60);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->assertSee('Cobrança aberta na maquininha Sunmi')
            ->assertSee('já pago R$ 60,00')
            ->call('abrirCobrancaStone', $cobranca->id)
            ->assertSet('modalStoneAberta', true)
            ->assertSet('stoneStatusModal', 'aguardando')
            ->assertSee('Já pago na maquininha')
            ->assertSee('Encerrar: o restante foi pago em outra forma')
            ->assertDontSee('Cancelar cobrança');
    }

    public function test_cobranca_sem_pagamento_oferece_cancelar_e_nao_encerrar(): void
    {
        $cobranca = $this->cobranca(StonePedidoStatus::Aguardando);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('abrirCobrancaStone', $cobranca->id)
            ->assertSee('Cancelar cobrança')
            ->assertDontSee('Encerrar: o restante foi pago em outra forma');
    }

    public function test_nao_reabre_cobranca_de_outra_venda_nem_ja_encerrada(): void
    {
        $outraVenda = Venda::create(['venda_status' => 'INICIADA', 'venda_valor_total' => 10]);
        $alheia = StonePedido::create([
            'stp_venda_id' => $outraVenda->id, 'stp_maquininha_id' => $this->sunmi->id, 'stp_origem' => StonePedidoOrigem::Venda,
            'stp_order_id' => 'or_alheia', 'stp_valor_solicitado' => 10, 'stp_status' => StonePedidoStatus::Aguardando, 'stp_modo' => StonePedidoModo::Listado,
        ]);
        $paga = $this->cobranca(StonePedidoStatus::Pago, 100);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('abrirCobrancaStone', $alheia->id)
            ->assertSet('modalStoneAberta', false)
            ->call('abrirCobrancaStone', $paga->id)
            ->assertSet('modalStoneAberta', false);
    }

    public function test_encerrar_fecha_na_stone_e_libera_a_finalizacao_da_venda(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);
        $cobranca = $this->cobranca(StonePedidoStatus::PagoParcial, 60, ['stp_order_id' => 'or_parcial']);
        PagamentosVenda::create([
            'pg_venda_venda_id' => $this->venda->id, 'pg_venda_tipo_integracao' => 'integrated',
            'pg_venda_valor_pagamento' => 60, 'pg_venda_valor_recebido' => 60, 'pg_venda_valor_pago_pelo_cliente' => 60, 'pg_venda_valor_troco' => 0,
        ]);
        $this->venda->update(['venda_valor_pago' => 60]);

        $tela = Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('abrirCobrancaStone', $cobranca->id)
            ->call('encerrarRestanteStone')
            ->assertSet('stoneStatusModal', 'encerrado')
            ->assertSee('Faltam R$ 40,00')
            ->call('fecharModalStone')
            ->assertDontSee('Cobrança aberta na maquininha');

        $cobranca->refresh();
        $this->assertSame(StonePedidoStatus::Pago, $cobranca->stp_status);
        $this->assertSame(60.0, (float) $cobranca->stp_valor_solicitado);
        $this->assertNotNull($cobranca->stp_fechado_em);
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && str_contains($request->url(), '/orders/or_parcial/closed') && $request['status'] === 'paid');

        $this->pagarEmDinheiro(40);
        $tela->call('finalizarVenda')->assertHasNoErrors();

        $this->assertSame('FINALIZADA', $this->venda->fresh()->venda_status);
    }

    public function test_com_cobranca_parcial_aberta_a_venda_nao_finaliza_e_o_cancelamento_e_recusado(): void
    {
        Http::fake();
        $cobranca = $this->cobranca(StonePedidoStatus::PagoParcial, 60);
        $this->pagarEmDinheiro(100);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('finalizarVenda')
            ->assertHasErrors(['finalizar'])
            ->call('abrirCobrancaStone', $cobranca->id)
            ->call('cancelarCobrancaStone')
            ->assertSet('stoneStatusModal', 'erro')
            ->assertSee('Encerrar cobrança');

        $this->assertSame(StonePedidoStatus::PagoParcial, $cobranca->fresh()->stp_status);
        Http::assertNothingSent();
    }

    public function test_recusa_da_stone_ao_encerrar_mostra_o_erro_e_mantem_a_cobranca(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['message' => 'Pedido não pode ser fechado'], 422)]);
        $cobranca = $this->cobranca(StonePedidoStatus::PagoParcial, 60);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('abrirCobrancaStone', $cobranca->id)
            ->call('encerrarRestanteStone')
            ->assertSet('stoneStatusModal', 'erro')
            ->assertSee('Pedido não pode ser fechado');

        $this->assertSame(StonePedidoStatus::PagoParcial, $cobranca->fresh()->stp_status);
    }
}
