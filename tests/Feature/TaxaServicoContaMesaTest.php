<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoOrigem;
use App\Filament\Pages\OperarVenda;
use App\Jobs\EmitirNfeAutomaticaJob;
use App\Livewire\MesaStoneCobranca;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\ItensPedido;
use App\Models\Maquininha;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\StonePedido;
use App\Models\User;
use App\Models\Venda;
use App\Services\Nfe\EmissaoNfeAutomaticaService;
use App\Services\NfeIoService;
use App\Services\SessaoMesaService;
use App\Support\ContaMesa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Taxa de serviço da conta de mesa: snapshot na sessão, pré-conta, cobrança
 * Stone, total da venda (PDV e webhook) e a NFC-e automática da venda que o
 * webhook finaliza.
 */
class TaxaServicoContaMesaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Produto $produto;

    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pizzaria.salao.taxa_servico_percentual' => 10, 'pizzaria.salao.taxa_servico_padrao_ligada' => true]);

        $this->user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->mesa = Mesa::create(['mesa_nome' => 'Mesa 7', 'mesa_status' => 'LIBERADA']);
        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50.00,
            'produto_valor_percentual_icms' => 10,
            'produto_valor_percentual_pis' => 1,
            'produto_valor_percentual_cofins' => 2,
        ]);
    }

    private function sessao(?float $taxa = null): SessaoMesa
    {
        return app(SessaoMesaService::class)->abrir($this->mesa->id, $this->user->id, taxaServicoPercentual: $taxa);
    }

    private function rodada(SessaoMesa $sessao, string $status = 'ENTREGUE', float $valor = 50.00): Pedido
    {
        $pedido = Pedido::create(['pedido_sessao_mesa_id' => $sessao->id, 'pedido_status' => $status, 'pedido_valor_total' => $valor]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => $valor,
            'item_pedido_valor' => $valor,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return $pedido;
    }

    private function sessaoCaixa(): SessaoCaixa
    {
        return SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_saldo_final' => 0,
            'sessaocaixa_user_id' => $this->user->id,
        ]);
    }

    public function test_conta_soma_a_taxa_e_ignora_rascunho_de_rodada(): void
    {
        $sessao = $this->sessao();
        $this->rodada($sessao, 'ENTREGUE', 50.00);
        $this->rodada($sessao, 'PREPARANDO', 30.00);
        $this->rodada($sessao, 'INICIADO', 99.00);

        $this->assertSame(
            ['subtotal' => 80.0, 'percentual' => 10.0, 'taxa' => 8.0, 'total' => 88.0],
            ContaMesa::para($sessao->fresh()),
        );
    }

    public function test_sessao_sem_taxa_nao_cobra_taxa(): void
    {
        $sessao = $this->sessao(0.0);
        $this->rodada($sessao);

        $this->assertSame(50.0, ContaMesa::para($sessao->fresh())['total']);
    }

    public function test_pdv_lanca_a_mesa_com_a_taxa_no_total_da_venda_e_sem_o_rascunho(): void
    {
        $this->actingAs($this->user);
        $this->sessaoCaixa();
        $sessao = $this->sessao();
        $this->rodada($sessao, 'ENTREGUE', 50.00);
        $rascunho = $this->rodada($sessao, 'INICIADO', 99.00);

        Livewire::test(OperarVenda::class)->call('lancarItensDaMesa', $sessao->id);

        $venda = Venda::sole();
        $this->assertEquals(5.00, $venda->venda_valor_taxa_servico);
        $this->assertEquals(55.00, $venda->venda_valor_total);
        $this->assertNull($rascunho->item_pedido_pedido_id()->first()->item_pedido_venda_id);
    }

    public function test_cobranca_stone_da_mesa_inclui_a_taxa(): void
    {
        $this->actingAs($this->user);
        $sessao = $this->sessao();
        $this->rodada($sessao);

        Livewire::test(MesaStoneCobranca::class, ['sessaoMesaId' => $sessao->id])
            ->assertSet('totalAberto', 55.0);
    }

    public function test_webhook_stone_fecha_a_conta_com_taxa_e_despacha_a_nfce(): void
    {
        Queue::fake();
        config([
            'services.stone.webhook_user' => null,
            'services.stone.webhook_password' => null,
            'services.stone.secret_key' => 'sk_test_abc',
            'services.stone.base_url' => 'https://api.pagar.me/core/v5',
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);

        $this->sessaoCaixa();
        $sessao = $this->sessao();
        $this->rodada($sessao);

        StonePedido::create([
            'stp_sessao_mesa_id' => $sessao->id,
            'stp_origem' => StonePedidoOrigem::SessaoMesa,
            'stp_maquininha_id' => Maquininha::create(['nome' => 'Salão', 'operadora' => 'stone', 'numero_serie' => '6N0001'])->id,
            'stp_order_id' => 'or_taxa_1',
            'stp_order_code' => 'TAXA1',
            'stp_valor_solicitado' => 55.00,
            'stp_status' => 'aguardando',
            'stp_modo' => StonePedidoModo::Listado->value,
        ]);

        $this->postJson('/api/webhook/stone-connect', [
            'id' => 'hook_taxa_1',
            'type' => 'charge.paid',
            'created_at' => '2026-10-02T20:00:00Z',
            'data' => [
                'id' => 'ch_taxa_1',
                'code' => '1',
                'amount' => 5500,
                'paid_amount' => 5500,
                'status' => 'paid',
                'payment_method' => 'credit_card',
                'order' => ['id' => 'or_taxa_1', 'code' => 'TAXA1', 'amount' => 5500, 'closed' => false, 'status' => 'pending', 'metadata' => []],
                'metadata' => ['scheme_name' => 'MasterCard', 'authorization_code' => 'A1'],
            ],
        ])->assertOk();

        $venda = Venda::sole();
        $this->assertSame('FINALIZADA', $venda->venda_status);
        $this->assertEquals(5.00, $venda->venda_valor_taxa_servico);
        $this->assertEquals(55.00, $venda->venda_valor_total);
        $this->assertSame('LIBERADA', $this->mesa->fresh()->mesa_status);

        Queue::assertPushed(EmitirNfeAutomaticaJob::class, fn (EmitirNfeAutomaticaJob $job) => $job->vendaId === $venda->id);
    }

    public function test_job_so_emite_quando_a_forma_de_pagamento_exige(): void
    {
        $venda = Venda::create(['venda_status' => 'FINALIZADA']);

        $this->mock(EmissaoNfeAutomaticaService::class, function (MockInterface $mock) {
            $mock->shouldReceive('deveEmitir')->once()->andReturn(false);
            $mock->shouldNotReceive('emitir');
        });

        (new EmitirNfeAutomaticaJob($venda->id))->handle(app(EmissaoNfeAutomaticaService::class));
    }

    public function test_job_emite_venda_finalizada_ainda_sem_nota(): void
    {
        $venda = Venda::create(['venda_status' => 'FINALIZADA']);

        $this->mock(EmissaoNfeAutomaticaService::class, function (MockInterface $mock) {
            $mock->shouldReceive('deveEmitir')->once()->andReturn(true);
            $mock->shouldReceive('emitir')->once();
        });

        (new EmitirNfeAutomaticaJob($venda->id))->handle(app(EmissaoNfeAutomaticaService::class));
    }

    public function test_nfce_leva_a_taxa_de_servico_em_outras_despesas_rateada(): void
    {
        $this->actingAs($this->user);
        $this->sessaoCaixa();
        $sessao = $this->sessao();
        $this->rodada($sessao, 'ENTREGUE', 50.00);
        $this->rodada($sessao, 'ENTREGUE', 30.00);

        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11222333000181',
            'empresa_api_nfeio_company_id' => 'company-teste',
            'empresa_api_nfeio_apikey' => 'chave-teste',
        ]);

        Livewire::test(OperarVenda::class)->call('lancarItensDaMesa', $sessao->id);
        $venda = Venda::sole();

        Http::preventStrayRequests();
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'inv-1', 'status' => 'Processing'])]);

        app(NfeIoService::class)->emitir($venda->fresh());

        Http::assertSent(fn ($request) => round(array_sum(array_column($request['items'], 'othersAmount')), 2) === 8.0);
    }
}
