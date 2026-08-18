<?php

namespace Tests\Feature;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\OperarVenda;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\ItensPedido;
use App\Models\Lancamento;
use App\Models\Mesa;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class OperarVendaFinalizarCancelarTest extends TestCase
{
    use RefreshDatabase;

    private SessaoCaixa $sessaoCaixa;

    private Venda $venda;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);

        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $this->sessaoCaixa = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);

        $this->venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $this->sessaoCaixa->id,
            'venda_valor_total' => 0,
            'venda_valor_pago' => 0,
            'venda_valor_troco' => 0,
        ]);
    }

    public function test_finalizar_venda_zerada_redireciona_para_a_propria_operar_venda(): void
    {
        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('finalizarVenda')
            ->assertRedirect(OperarVenda::getUrl());

        $this->assertSame('FINALIZADA', $this->venda->fresh()->venda_status);
        $this->assertSame(1, MovimentacoesSessaoCaixa::where('mov_venda_id', $this->venda->id)->count());
    }

    public function test_finalizar_venda_com_pagamento_insuficiente_mostra_erro_e_nao_finaliza(): void
    {
        $this->venda->update(['venda_valor_total' => 50, 'venda_valor_pago' => 30]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('finalizarVenda')
            ->assertHasErrors('finalizar');

        $this->assertSame('INICIADA', $this->venda->fresh()->venda_status);
    }

    private function produtoParaItemPedido(): Produto
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);

        return Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50,
        ]);
    }

    public function test_finalizar_venda_finaliza_mesa_vinculada_via_itens_lancados(): void
    {
        $mesa = Mesa::create(['mesa_nome' => 'Mesa 1', 'mesa_status' => 'LIBERADA']);
        $sessaoMesa = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_usuario_id' => auth()->id(),
            'sessao_mesa_status' => 'ABERTA',
        ]);
        $mesa->update(['mesa_status' => 'OCUPADA', 'mesa_sessao_atual_id' => $sessaoMesa->id]);

        $pedido = Pedido::create(['pedido_sessao_mesa_id' => $sessaoMesa->id, 'pedido_status' => 'ENTREGUE']);
        $item = ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produtoParaItemPedido()->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_status' => 'INSERIDO',
            'item_pedido_venda_id' => $this->venda->id,
        ]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('finalizarVenda');

        $this->assertSame('FINALIZADA', $sessaoMesa->fresh()->sessao_mesa_status);
        $this->assertSame('LIBERADA', $mesa->fresh()->mesa_status);
        $this->assertSame($this->venda->id, $pedido->fresh()->pedido_venda_id);
        $this->assertSame($this->venda->id, $item->fresh()->item_pedido_venda_id);
    }

    public function test_finalizar_venda_finaliza_pedido_avulso_vinculado_via_itens_lancados(): void
    {
        $pedido = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produtoParaItemPedido()->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_status' => 'INSERIDO',
            'item_pedido_venda_id' => $this->venda->id,
        ]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('finalizarVenda');

        $pedidoFresh = $pedido->fresh();
        $this->assertSame('FINALIZADO', $pedidoFresh->pedido_status);
        $this->assertSame($this->venda->id, $pedidoFresh->pedido_venda_id);
    }

    public function test_cancelar_venda_libera_itens_e_redireciona_para_nova_venda(): void
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50,
        ]);
        $pedido = Pedido::create(['pedido_status' => 'ABERTO']);
        $item = ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_status' => 'INSERIDO',
            'item_pedido_venda_id' => $this->venda->id,
        ]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('motivoCancelamento', MotivoCancelamentoEnum::CLIENTE_DESISTIU->value)
            ->call('confirmarCancelamento')
            ->assertRedirect(OperarVenda::getUrl());

        $vendaFresh = $this->venda->fresh();
        $this->assertSame('CANCELADA', $vendaFresh->venda_status);
        $this->assertSame(MotivoCancelamentoEnum::CLIENTE_DESISTIU, $vendaFresh->venda_motivo_cancelamento);
        $this->assertNull($item->fresh()->item_pedido_venda_id);
    }

    public function test_confirmar_cancelamento_sem_motivo_nao_faz_nada(): void
    {
        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('confirmarCancelamento');

        $this->assertSame('INICIADA', $this->venda->fresh()->venda_status);
    }

    private function configurarNfeIo(): void
    {
        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11222333000181',
            'empresa_api_nfeio_company_id' => 'company-teste',
            'empresa_api_nfeio_apikey' => 'chave-teste',
        ]);
    }

    public function test_finalizar_venda_sem_checkbox_marcado_mantem_comportamento_atual(): void
    {
        $this->configurarNfeIo();

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('finalizarVenda')
            ->assertRedirect(OperarVenda::getUrl())
            ->assertSet('modalNfeAberta', false);

        $this->assertSame('FINALIZADA', $this->venda->fresh()->venda_status);
    }

    public function test_finalizar_venda_com_checkbox_marcado_abre_modal_e_nao_redireciona(): void
    {
        $this->configurarNfeIo();
        Http::fake([
            'api.nfse.io/*' => Http::response(['id' => 'inv-123'], 200),
        ]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('emitirNfeAoFinalizar', true)
            ->call('finalizarVenda')
            ->assertNoRedirect()
            ->assertSet('modalNfeAberta', true)
            ->assertSet('nfeStatusModal', 'processando');

        // A finalização em si já aconteceu — só o redirect fica pendente do modal.
        $this->assertSame('FINALIZADA', $this->venda->fresh()->venda_status);
        $this->assertSame('inv-123', $this->venda->fresh()->venda_id_nfe);
    }

    public function test_verificar_status_nfe_transiciona_de_processando_para_emitido(): void
    {
        $this->configurarNfeIo();
        Http::fake([
            'api.nfse.io/v2/companies/company-teste/consumerinvoices' => Http::response(['id' => 'inv-123'], 200),
            'api.nfse.io/v2/companies/company-teste/consumerinvoices/inv-123' => Http::response(['id' => 'inv-123', 'status' => 'Issued'], 200),
        ]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('emitirNfeAoFinalizar', true)
            ->call('finalizarVenda')
            ->call('verificarStatusNfe')
            ->assertSet('nfeStatusModal', 'emitido');
    }

    public function test_iniciar_emissao_nfe_com_erro_mostra_mensagem_e_mantem_modal_aberto(): void
    {
        $this->configurarNfeIo();
        Http::fake([
            'api.nfse.io/*' => Http::response(['message' => 'CNPJ inválido'], 400),
        ]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('emitirNfeAoFinalizar', true)
            ->call('finalizarVenda')
            ->assertSet('modalNfeAberta', true)
            ->assertSet('nfeStatusModal', 'erro')
            ->assertSee('CNPJ inválido');

        $this->assertNull($this->venda->fresh()->venda_id_nfe);
    }

    public function test_fechar_modal_nfe_redireciona_para_propria_pagina(): void
    {
        $this->configurarNfeIo();
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'inv-123'], 200)]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('emitirNfeAoFinalizar', true)
            ->call('finalizarVenda')
            ->call('fecharModalNfe')
            ->assertRedirect(OperarVenda::getUrl());
    }

    // ── Venda fiado / a prazo ────────────────────────────────────────────────

    public function test_finalizar_venda_fiado_sem_cliente_vinculado_bloqueia(): void
    {
        $this->venda->update(['venda_valor_total' => 100, 'venda_valor_pago' => 0]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('vendaFiado', true)
            ->call('finalizarVenda')
            ->assertHasErrors('finalizar');

        $this->assertSame('INICIADA', $this->venda->fresh()->venda_status);
        $this->assertSame(0, Lancamento::count());
    }

    public function test_finalizar_venda_fiado_com_cliente_sem_limite_configurado_bloqueia(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'João', 'cliente_tipo' => 'PF']);
        $this->venda->update(['venda_valor_total' => 100, 'venda_valor_pago' => 0, 'venda_cliente_id' => $cliente->id]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('vendaFiado', true)
            ->call('finalizarVenda')
            ->assertHasErrors('finalizar');

        $this->assertSame('INICIADA', $this->venda->fresh()->venda_status);
        $this->assertSame(0, Lancamento::count());
    }

    public function test_finalizar_venda_fiado_dentro_do_limite_libera_e_cria_lancamento_receber(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'João', 'cliente_tipo' => 'PF', 'cliente_limite_credito' => 200]);
        $this->venda->update(['venda_valor_total' => 100, 'venda_valor_pago' => 0, 'venda_cliente_id' => $cliente->id]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('vendaFiado', true)
            ->set('fiadoVencimento', '2026-09-01')
            ->call('finalizarVenda')
            ->assertRedirect(OperarVenda::getUrl());

        $this->assertSame('FINALIZADA', $this->venda->fresh()->venda_status);

        $lancamento = Lancamento::first();
        $this->assertNotNull($lancamento);
        $this->assertSame($this->venda->id, $lancamento->venda_id);
        $this->assertSame($cliente->id, $lancamento->cliente_id);
        $this->assertSame(100.0, (float) $lancamento->valor);
        $this->assertSame('pendente', $lancamento->status->value);
        $this->assertSame('2026-09-01', $lancamento->vencimento->toDateString());
    }

    public function test_finalizar_venda_fiado_excedendo_limite_bloqueia_e_nao_finaliza(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'João', 'cliente_tipo' => 'PF', 'cliente_limite_credito' => 50]);
        $this->venda->update(['venda_valor_total' => 100, 'venda_valor_pago' => 0, 'venda_cliente_id' => $cliente->id]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('vendaFiado', true)
            ->call('finalizarVenda')
            ->assertHasErrors('finalizar');

        $this->assertSame('INICIADA', $this->venda->fresh()->venda_status);
        $this->assertSame(0, Lancamento::count());
    }

    public function test_finalizar_venda_fiado_com_pagamento_parcial_gera_titulo_pelo_saldo_restante(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'João', 'cliente_tipo' => 'PF', 'cliente_limite_credito' => 200]);
        $this->venda->update(['venda_valor_total' => 100, 'venda_valor_pago' => 40, 'venda_cliente_id' => $cliente->id]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('vendaFiado', true)
            ->call('finalizarVenda')
            ->assertRedirect(OperarVenda::getUrl());

        $this->assertSame('FINALIZADA', $this->venda->fresh()->venda_status);
        $this->assertSame(60.0, (float) Lancamento::first()->valor);
    }

    public function test_movimentacao_de_caixa_em_venda_fiado_usa_valor_pago_nao_o_total(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'João', 'cliente_tipo' => 'PF', 'cliente_limite_credito' => 200]);
        $this->venda->update(['venda_valor_total' => 100, 'venda_valor_pago' => 40, 'venda_cliente_id' => $cliente->id]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('vendaFiado', true)
            ->call('finalizarVenda');

        $mov = MovimentacoesSessaoCaixa::where('mov_venda_id', $this->venda->id)->first();
        $this->assertSame(40.0, (float) $mov->mov_valor);
        $this->assertSame(40.0, (float) $this->sessaoCaixa->fresh()->sessaocaixa_saldo_final);
    }
}
